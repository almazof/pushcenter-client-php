<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PushCenter\Client\ClientConfig;
use PushCenter\Client\Exception\ApiException;
use PushCenter\Client\Exception\TransportException;
use PushCenter\Client\Payload\Payload;
use PushCenter\Client\Payload\PayloadBuilder;
use PushCenter\Client\PushCenterClient;
use PushCenter\Client\Retry\RetryConfig;
use PushCenter\Client\Tests\Support\FakeTransport;
use PushCenter\Client\Tests\Support\RecordingSleeper;
use PushCenter\Client\Tests\Support\SpyLogger;

final class FireAndForgetTest extends TestCase
{
    private FakeTransport $transport;
    private SpyLogger $logger;
    private PushCenterClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->logger = new SpyLogger();
        $this->client = new PushCenterClient(
            new ClientConfig('http://gateway.test', 'k', RetryConfig::none()),
            $this->transport,
            $this->logger,
            new RecordingSleeper(),
        );
    }

    private static function payload(): Payload
    {
        return (new PayloadBuilder())->event('x_event', 'evt-1')->ui('T', 'B')->build();
    }

    public function testNotifyReturnsFalseAndLogsInsteadOfThrowing(): void
    {
        $this->transport->willRespond(new TransportException('connection refused'));

        $result = $this->client->fireAndForget()->notifyUser('u1', self::payload());

        self::assertFalse($result);
        self::assertTrue($this->logger->hasErrorContaining('fire-and-forget'));
    }

    public function testDefaultModeStillThrows(): void
    {
        $this->transport->willRespond(new TransportException('connection refused'));

        $this->expectException(TransportException::class);
        $this->client->notifyUser('u1', self::payload());
    }

    public function testFireAndForgetReturnsACopyLeavingOriginalStrict(): void
    {
        $soft = $this->client->fireAndForget();
        self::assertNotSame($soft, $this->client);

        $this->transport->willRespond(new TransportException('down'));
        self::assertFalse($soft->notifyUser('u1', self::payload()));

        $this->transport->willRespond(new TransportException('down'));
        $this->expectException(TransportException::class);
        $this->client->notifyUser('u1', self::payload());
    }

    public function testClientSideValidationFailureIsNotSilenced(): void
    {
        // Invalid UTF-8 in user_id passes the cheap length check but makes
        // the body non-JSON-serializable in postJson(). That is a caller
        // bug, not a delivery failure — fire-and-forget must NOT swallow it.
        $this->expectException(\PushCenter\Client\Exception\ValidationException::class);
        $this->client->fireAndForget()->notifyUser("\xC3\x28", self::payload());
    }

    public function testRegistryCallsAreNotSilenced(): void
    {
        // fire-and-forget covers notify* only: a failed register/bind is a
        // real integration problem the project must see.
        $this->transport->willRespond(FakeTransport::json(500, '{"error":{"code":"server_error","message":"x"}}'));

        $this->expectException(ApiException::class);
        $this->client->fireAndForget()->unbindUser('3f2a1b6c-9d4e-4f7a-8b2c-1e5d6f7a8b9c');
    }
}
