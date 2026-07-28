<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PushCenter\Client\ClientConfig;
use PushCenter\Client\Dto\NotifyOptions;
use PushCenter\Client\Dto\Platform;
use PushCenter\Client\Dto\RegisterDeviceRequest;
use PushCenter\Client\Dto\TokenTarget;
use PushCenter\Client\Payload\Payload;
use PushCenter\Client\PushCenterClient;
use PushCenter\Client\Retry\RetryConfig;
use PushCenter\Client\Tests\Support\ContractPaths;
use PushCenter\Client\Tests\Support\FakeTransport;

/**
 * Golden fixtures (see tests/fixtures/README.md): every valid request fixture of the
 * contract must be constructible through the client API. Comparison is
 * JSON-semantic (decoded arrays; PHP `==` ignores key order). The single
 * deliberate normalization: explicit `"user_id": null` in a fixture equals
 * an omitted user_id (the contract treats null and absence identically).
 */
final class FixtureRequestSerializationTest extends TestCase
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
    }

    /** @param array<string, mixed> $fixture */
    private function assertBodyMatchesFixture(array $fixture): void
    {
        $sent = $this->transport->requestBody(0);
        // null user_id == absent user_id (semantic equivalence, see class doc)
        if (array_key_exists('user_id', $fixture) && $fixture['user_id'] === null) {
            unset($fixture['user_id']);
        }
        self::assertEquals($fixture, $sent);
    }

    private function respondOk(string $body = '{"registered": true}'): void
    {
        $this->transport->willRespond(FakeTransport::json(200, $body));
    }

    public function testRegisterMinimal(): void
    {
        $fixture = ContractPaths::fixture('valid/device-register-request.minimal.json');
        $this->respondOk();

        $this->client->registerDevice(new RegisterDeviceRequest(
            installId: '3f2a1b6c-9d4e-4f7a-8b2c-1e5d6f7a8b9c',
            deviceToken: 'fcm-token-abc123',
            platform: Platform::Android,
        ));

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testRegisterFull(): void
    {
        $fixture = ContractPaths::fixture('valid/device-register-request.full.json');
        $this->respondOk();

        $this->client->registerDevice(new RegisterDeviceRequest(
            installId: '3F2A1B6C-9D4E-4F7A-8B2C-1E5D6F7A8B9C',
            deviceToken: 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4',
            platform: Platform::Ios,
            userId: 'user-102938',
            appType: 'admin',
        ));

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testRegisterGuestNullUser(): void
    {
        $fixture = ContractPaths::fixture('valid/device-register-request.guest-null-user.json');
        $this->respondOk();

        $this->client->registerDevice(new RegisterDeviceRequest(
            installId: '3f2a1b6c-9d4e-4f7a-8b2c-1e5d6f7a8b9c',
            deviceToken: 't',
            platform: Platform::Android,
            userId: null,
        ));

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testRegisterWithLocale(): void
    {
        $fixture = ContractPaths::fixture('valid/device-register-request.with-locale.json');
        $this->respondOk();

        $this->client->registerDevice(new RegisterDeviceRequest(
            installId: '3f2a1b6c-9d4e-4f7a-8b2c-1e5d6f7a8b9c',
            deviceToken: 'fcm-token-abc123',
            platform: Platform::Android,
            userId: 'user-102938',
            locale: 'ru',
        ));

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testBindMinimalAndFull(): void
    {
        foreach (['minimal', 'full'] as $variant) {
            $this->setUp();
            $fixture = ContractPaths::fixture("valid/device-bind-request.{$variant}.json");
            $this->respondOk('{"bound": true}');

            self::assertIsString($fixture['install_id']);
            self::assertIsString($fixture['user_id']);
            $this->client->bindUser($fixture['install_id'], $fixture['user_id']);

            $this->assertBodyMatchesFixture($fixture);
        }
    }

    public function testUnbindMinimalAndFull(): void
    {
        foreach (['minimal', 'full'] as $variant) {
            $this->setUp();
            $fixture = ContractPaths::fixture("valid/device-unbind-request.{$variant}.json");
            $this->respondOk('{"unbound": true}');

            self::assertIsString($fixture['install_id']);
            $this->client->unbindUser($fixture['install_id']);

            $this->assertBodyMatchesFixture($fixture);
        }
    }

    public function testNotificationMinimalToUserId(): void
    {
        $fixture = ContractPaths::fixture('valid/notification-request.minimal.to-user-id.json');
        $this->respondOk('{"status": "enqueued"}');

        self::assertIsArray($fixture['payload']);
        self::assertIsString($fixture['idempotency_key']);
        $result = $this->client->notifyUser(
            'user-102938',
            Payload::fromArray($fixture['payload']),
            new NotifyOptions(idempotencyKey: $fixture['idempotency_key']),
        );

        self::assertNotFalse($result);
        self::assertSame('enqueued', $result->status);
        $this->assertBodyMatchesFixture($fixture);
    }

    public function testNotificationFullToInstallId(): void
    {
        $fixture = ContractPaths::fixture('valid/notification-request.full.to-install-id.json');
        $this->respondOk('{"status": "enqueued"}');

        self::assertIsArray($fixture['payload']);
        self::assertIsString($fixture['idempotency_key']);
        self::assertIsString($fixture['collapse_id']);
        self::assertIsInt($fixture['ttl']);
        self::assertIsArray($fixture['to']);
        self::assertIsString($fixture['to']['install_id']);
        // Everything is taken from the fixture: the assertion is about the
        // serialized SHAPE, and hardcoded sample values would turn cosmetic
        // fixture edits into failures.
        $this->client->notifyInstall(
            $fixture['to']['install_id'],
            Payload::fromArray($fixture['payload']),
            new NotifyOptions(
                collapseId: $fixture['collapse_id'],
                ttl: $fixture['ttl'],
                idempotencyKey: $fixture['idempotency_key'],
            ),
        );

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testNotificationToTokens(): void
    {
        $fixture = ContractPaths::fixture('valid/notification-request.to-tokens.json');
        $this->respondOk('{"status": "enqueued"}');

        self::assertIsArray($fixture['payload']);
        self::assertIsString($fixture['idempotency_key']);
        $this->client->notifyTokens(
            [
                new TokenTarget(
                    'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4',
                    Platform::Ios,
                ),
                new TokenTarget('fcm-token-abc123', Platform::Android),
            ],
            Payload::fromArray($fixture['payload']),
            new NotifyOptions(ttl: 0, idempotencyKey: $fixture['idempotency_key']),
        );

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testNotificationToUserIdWithLocale(): void
    {
        $fixture = ContractPaths::fixture('valid/notification-request.to-user-id-with-locale.json');
        $this->respondOk('{"status": "enqueued"}');

        self::assertIsArray($fixture['payload']);
        self::assertIsString($fixture['idempotency_key']);
        $this->client->notifyUser(
            'user-102938',
            Payload::fromArray($fixture['payload']),
            new NotifyOptions(locale: 'en-US', idempotencyKey: $fixture['idempotency_key']),
        );

        $this->assertBodyMatchesFixture($fixture);
    }

    public function testNotificationBoundaryPayloadExactly3500Bytes(): void
    {
        $fixture = ContractPaths::fixture('valid/notification-request.boundary.payload-exactly-3500-bytes.json');
        $this->respondOk('{"status": "enqueued"}');

        self::assertIsArray($fixture['payload']);
        self::assertIsString($fixture['idempotency_key']);
        $payload = Payload::fromArray($fixture['payload']);
        self::assertSame(3500, $payload->encodedBytes());

        $this->client->notifyUser(
            'u1',
            $payload,
            new NotifyOptions(idempotencyKey: $fixture['idempotency_key']),
        );

        $this->assertBodyMatchesFixture($fixture);
    }
}
