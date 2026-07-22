<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PushCenter\Client\ClientConfig;
use PushCenter\Client\Dto\BroadcastAudience;
use PushCenter\Client\Dto\BroadcastFilters;
use PushCenter\Client\Dto\NotifyOptions;
use PushCenter\Client\Dto\Platform;
use PushCenter\Client\Exception\ValidationException;
use PushCenter\Client\Payload\Payload;
use PushCenter\Client\PushCenterClient;
use PushCenter\Client\Retry\RetryConfig;
use PushCenter\Client\Tests\Support\ContractPaths;
use PushCenter\Client\Tests\Support\FakeTransport;

/**
 * notifyBroadcast() against the golden broadcast fixtures of the contract
 * (ROADMAP principle 3): every valid `to.broadcast` fixture must be
 * constructible through the typed client API and serialize byte-compatibly.
 */
final class BroadcastRequestTest extends TestCase
{
    private FakeTransport $transport;
    private PushCenterClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new PushCenterClient(
            new ClientConfig('http://gateway.test', 'test-key', RetryConfig::none()),
            $this->transport,
        );
        $this->transport->willRespond(FakeTransport::json(202, '{"status": "enqueued"}'));
    }

    /** @param array<string, mixed> $fixture */
    private function assertBodyMatchesFixture(array $fixture): void
    {
        self::assertEquals($fixture, $this->transport->requestBody(0));
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return ContractPaths::fixture('valid/' . $name);
    }

    /** @param array<string, mixed> $fixture */
    private function send(array $fixture, ?BroadcastFilters $filters): void
    {
        self::assertIsArray($fixture['payload']);
        self::assertIsString($fixture['idempotency_key']);

        $result = $this->client->notifyBroadcast(
            Payload::fromArray($fixture['payload']),
            $filters,
            new NotifyOptions(idempotencyKey: $fixture['idempotency_key']),
        );

        self::assertNotFalse($result);
        self::assertSame('enqueued', $result->status);
    }

    public function testBroadcastToEveryActiveDevice(): void
    {
        $fixture = $this->fixture('notification-request.to-broadcast-all.json');

        $this->send($fixture, null);

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testExplicitEmptyFiltersSerializeAsAnEmptyObject(): void
    {
        $fixture = $this->fixture('notification-request.to-broadcast-all.json');

        $this->send($fixture, new BroadcastFilters());

        $this->assertBodyMatchesFixture($fixture);
        self::assertStringContainsString('"broadcast":{}', (string) $this->transport->requests[0]->body);
    }

    public function testPlatformFilter(): void
    {
        $fixture = $this->fixture('notification-request.to-broadcast-platform.json');

        $this->send($fixture, new BroadcastFilters(platform: Platform::Ios));

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testLocaleFilter(): void
    {
        $fixture = $this->fixture('notification-request.to-broadcast-locale.json');

        $this->send($fixture, new BroadcastFilters(locale: 'en-US'));

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testAppTypeFilter(): void
    {
        $fixture = $this->fixture('notification-request.to-broadcast-app-type.json');

        $this->send($fixture, new BroadcastFilters(appType: 'client'));

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testAudienceFilter(): void
    {
        $fixture = $this->fixture('notification-request.to-broadcast-audience-guest.json');

        $this->send($fixture, new BroadcastFilters(audience: BroadcastAudience::Guest));

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testAllFiltersCombined(): void
    {
        $fixture = $this->fixture('notification-request.to-broadcast-all-filters.json');

        $this->send($fixture, new BroadcastFilters(
            platform: Platform::Android,
            locale: 'ru',
            appType: 'admin',
            audience: BroadcastAudience::Authenticated,
        ));

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testInvalidLocaleIsRejectedBeforeTheWire(): void
    {
        $this->expectException(ValidationException::class);

        new BroadcastFilters(locale: 'ru_RU');
    }

    public function testInvalidAppTypeIsRejectedBeforeTheWire(): void
    {
        $this->expectException(ValidationException::class);

        new BroadcastFilters(appType: 'Client');
    }

    /**
     * to.locale belongs to user_id addressing; the broadcast branch carries
     * its own locale filter. Silently dropping the option would send a
     * different audience than the caller asked for.
     */
    public function testNotifyOptionsLocaleIsRejectedForBroadcasts(): void
    {
        $this->expectException(ValidationException::class);

        $this->client->notifyBroadcast(
            Payload::fromArray([
                'event' => ['type' => 'ping', 'id' => 'e'],
                'ui' => ['title' => 'T', 'body' => 'B'],
            ]),
            null,
            new NotifyOptions(locale: 'ru'),
        );
    }

    public function testRetryKeepsTheSameIdempotencyKeySoNoSecondRolloutStarts(): void
    {
        $transport = new FakeTransport();
        $transport->willRespond(
            FakeTransport::json(503, '{"error":{"code":"service_unavailable","message":"down"}}'),
            FakeTransport::json(202, '{"status": "enqueued"}'),
        );
        $client = new PushCenterClient(
            new ClientConfig('http://gateway.test', 'k', new RetryConfig(maxAttempts: 2, baseDelayMs: 0)),
            $transport,
            sleeper: new \PushCenter\Client\Tests\Support\RecordingSleeper(),
        );

        $result = $client->notifyBroadcast(Payload::fromArray([
            'event' => ['type' => 'ping', 'id' => 'e'],
            'ui' => ['title' => 'T', 'body' => 'B'],
        ]));

        self::assertNotFalse($result);
        self::assertCount(2, $transport->requests);
        self::assertSame(
            $transport->requestBody(0)['idempotency_key'],
            $transport->requestBody(1)['idempotency_key'],
            'a retry must never start a second broadcast',
        );
    }
}
