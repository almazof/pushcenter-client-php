<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PushCenter\Client\ClientConfig;
use PushCenter\Client\Dto\NotifyOptions;
use PushCenter\Client\Exception\ApiException;
use PushCenter\Client\Exception\RateLimitedException;
use PushCenter\Client\Exception\TransportException;
use PushCenter\Client\Exception\ValidationException;
use PushCenter\Client\Payload\PayloadBuilder;
use PushCenter\Client\PushCenterClient;
use PushCenter\Client\Payload\Payload;
use PushCenter\Client\Retry\RetryConfig;
use PushCenter\Client\Tests\Support\FakeTransport;
use PushCenter\Client\Tests\Support\RecordingSleeper;
use PushCenter\Client\Transport\TransportResponse;

/**
 * The contract retry rule (SPEC-API §5.1): 5xx/timeouts retried with the
 * SAME idempotency_key; 4xx never retried; 429 typed and not retried by
 * default.
 */
final class RetryBehaviourTest extends TestCase
{
    private FakeTransport $transport;
    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->sleeper = new RecordingSleeper();
    }

    private function client(?RetryConfig $retry = null): PushCenterClient
    {
        return new PushCenterClient(
            new ClientConfig('http://gateway.test', 'k', $retry ?? new RetryConfig(baseDelayMs: 10)),
            $this->transport,
            sleeper: $this->sleeper,
        );
    }

    private static function payload(): Payload
    {
        return (new PayloadBuilder())
            ->event('order_created', 'evt-1')
            ->ui('Title', 'Body')
            ->build();
    }

    private static function error(int $status, string $code): TransportResponse
    {
        return FakeTransport::json($status, sprintf('{"error":{"code":"%s","message":"boom"}}', $code));
    }

    public function testRetriesOn5xxWithSameIdempotencyKeyThenSucceeds(): void
    {
        $this->transport->willRespond(
            self::error(500, 'server_error'),
            self::error(503, 'service_unavailable'),
            FakeTransport::json(202, '{"status":"enqueued"}'),
        );

        $result = $this->client()->notifyUser('user-1', self::payload());

        self::assertNotFalse($result);
        self::assertSame('enqueued', $result->status);
        self::assertCount(3, $this->transport->requests);
        $keys = [];
        foreach ([0, 1, 2] as $i) {
            $key = $this->transport->requestBody($i)['idempotency_key'];
            self::assertIsString($key);
            $keys[] = $key;
        }
        self::assertCount(1, array_unique($keys), 'every retry must reuse the same idempotency_key');
        self::assertSame($result->idempotencyKey, $keys[0]);
        self::assertCount(2, $this->sleeper->sleptMs);
    }

    public function testRetriesOnNetworkTimeout(): void
    {
        $this->transport->willRespond(
            new TransportException('timeout', timedOut: true),
            FakeTransport::json(202, '{"status":"enqueued"}'),
        );

        $result = $this->client()->notifyUser('user-1', self::payload());

        self::assertNotFalse($result);
        self::assertCount(2, $this->transport->requests);
        self::assertSame(
            $this->transport->requestBody(0)['idempotency_key'],
            $this->transport->requestBody(1)['idempotency_key'],
        );
    }

    public function testExhaustedAttemptsThrowLastError(): void
    {
        $this->transport->willRespond(
            self::error(500, 'server_error'),
            self::error(500, 'server_error'),
            self::error(500, 'server_error'),
        );

        try {
            $this->client()->notifyUser('user-1', self::payload());
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(500, $e->statusCode);
            self::assertSame('server_error', $e->errorCode);
        }
        self::assertCount(3, $this->transport->requests);
    }

    public function test4xxIsNeverRetried(): void
    {
        $this->transport->willRespond(self::error(404, 'not_found'));

        try {
            $this->client()->bindUser('3f2a1b6c-9d4e-4f7a-8b2c-1e5d6f7a8b9c', 'u1');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('not_found', $e->errorCode);
        }
        self::assertCount(1, $this->transport->requests);
        self::assertSame([], $this->sleeper->sleptMs);
    }

    public function test422MapsToValidationException(): void
    {
        $this->transport->willRespond(self::error(422, 'validation_error'));

        $this->expectException(ValidationException::class);
        $this->client()->notifyUser('user-1', self::payload());
    }

    public function test429NotRetriedByDefaultAndCarriesRetryAfter(): void
    {
        $this->transport->willRespond(new TransportResponse(
            429,
            '{"error":{"code":"rate_limited","message":"slow down"}}',
            ['retry-after' => '17'],
        ));

        try {
            $this->client()->notifyUser('user-1', self::payload());
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame(17, $e->retryAfterSeconds);
            self::assertSame('rate_limited', $e->errorCode);
        }
        self::assertCount(1, $this->transport->requests);
    }

    public function test429RetriedWhenOptedIn(): void
    {
        $this->transport->willRespond(
            self::error(429, 'rate_limited'),
            FakeTransport::json(202, '{"status":"enqueued"}'),
        );

        $result = $this->client(new RetryConfig(baseDelayMs: 1, retryOn429: true))
            ->notifyUser('user-1', self::payload());

        self::assertNotFalse($result);
        self::assertCount(2, $this->transport->requests);
        self::assertSame(
            $this->transport->requestBody(0)['idempotency_key'],
            $this->transport->requestBody(1)['idempotency_key'],
        );
    }

    public function test429LimitExceededIsNeverRetriedEvenWhenOptedIn(): void
    {
        $this->transport->willRespond(self::error(429, 'limit_exceeded'));

        try {
            $this->client(new RetryConfig(baseDelayMs: 1, retryOn429: true))
                ->notifyUser('user-1', self::payload());
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame('limit_exceeded', $e->errorCode);
            self::assertTrue($e->isLimitExceeded());
        }
        self::assertCount(1, $this->transport->requests);
        self::assertSame([], $this->sleeper->sleptMs);
    }

    public function test429WithoutEnvelopeDefaultsToRateLimitedCode(): void
    {
        $this->transport->willRespond(new TransportResponse(429, 'plain text', []));

        try {
            $this->client()->notifyUser('user-1', self::payload());
            self::fail('expected RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame('rate_limited', $e->errorCode);
            self::assertFalse($e->isLimitExceeded());
        }
    }

    public function testRetryAfterFloorsBackoffDelayWhenRetryingOn429(): void
    {
        $this->transport->willRespond(
            new TransportResponse(
                429,
                '{"error":{"code":"rate_limited","message":"slow down"}}',
                ['retry-after' => '2'],
            ),
            FakeTransport::json(202, '{"status":"enqueued"}'),
        );

        $result = $this->client(new RetryConfig(baseDelayMs: 10, jitter: false, retryOn429: true))
            ->notifyUser('user-1', self::payload());

        self::assertNotFalse($result);
        self::assertSame([2000], $this->sleeper->sleptMs, 'delay must be max(backoff, Retry-After)');
    }

    public function testBackoffDelaysGrowAndRespectJitterBounds(): void
    {
        $config = new RetryConfig(maxAttempts: 4, baseDelayMs: 100, multiplier: 2.0, jitter: true);
        foreach ([1, 2, 3] as $attempt) {
            $slot = 100 * (2 ** ($attempt - 1));
            for ($i = 0; $i < 20; $i++) {
                $delay = $config->delayMsAfter($attempt);
                self::assertGreaterThanOrEqual((int) ($slot * 0.5), $delay);
                self::assertLessThanOrEqual($slot, $delay);
            }
        }
    }
}
