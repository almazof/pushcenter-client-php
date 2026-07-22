<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PushCenter\Client\Dto\NotifyOptions;
use PushCenter\Client\Dto\Platform;
use PushCenter\Client\Dto\RegisterDeviceRequest;
use PushCenter\Client\Exception\ValidationException;
use PushCenter\Client\Payload\Payload;
use PushCenter\Client\Validation;
use PushCenter\Client\Tests\Support\ContractPaths;

/**
 * Client-side pre-flight validation must reject every invalid contract
 * fixture whose rule IS checkable on the client (field patterns, limits,
 * payload structure, the 3500-byte limit). Rules only the gateway can
 * check (unknown-field additionalProperties on the full body, oneOf
 * addressing built from raw JSON, response-schema fixtures) stay
 * server-side by design.
 */
final class InvalidFixtureRejectionTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(string $name): array
    {
        return ContractPaths::fixture('invalid/' . $name);
    }

    public function testRegisterInstallIdNotUuidV4(): void
    {
        $f = self::fixture('device-register-request.install-id-not-uuid-v4.pattern.json');
        self::assertIsString($f['install_id']);
        $this->expectException(ValidationException::class);
        new RegisterDeviceRequest($f['install_id'], 'token', Platform::Ios);
    }

    public function testRegisterEmptyDeviceToken(): void
    {
        $this->expectException(ValidationException::class);
        new RegisterDeviceRequest('3f2a1b6c-9d4e-4f7a-8b2c-1e5d6f7a8b9c', '', Platform::Ios);
    }

    public function testRegisterDeviceTokenTooLong(): void
    {
        $f = self::fixture('device-register-request.device-token-too-long.maxLength.json');
        self::assertIsString($f['device_token']);
        $this->expectException(ValidationException::class);
        new RegisterDeviceRequest('3f2a1b6c-9d4e-4f7a-8b2c-1e5d6f7a8b9c', $f['device_token'], Platform::Ios);
    }

    public function testRegisterAppTypeBadFormat(): void
    {
        $f = self::fixture('device-register-request.app-type-bad-format.pattern.json');
        self::assertIsString($f['app_type']);
        $this->expectException(ValidationException::class);
        new RegisterDeviceRequest(
            '3f2a1b6c-9d4e-4f7a-8b2c-1e5d6f7a8b9c',
            'token',
            Platform::Ios,
            appType: $f['app_type'],
        );
    }

    public function testRegisterPlatformUnknownIsUnconstructible(): void
    {
        $f = self::fixture('device-register-request.platform-unknown.enum.json');
        self::assertIsString($f['platform']);
        // The type system itself forbids it: Platform::from() throws.
        $this->expectException(\ValueError::class);
        Platform::from($f['platform']);
    }

    public function testBindEmptyUserId(): void
    {
        $this->expectException(ValidationException::class);
        Validation::assertUserId('');
    }

    public function testBindInstallIdNotUuidV4(): void
    {
        $f = self::fixture('device-bind-request.install-id-not-uuid-v4.pattern.json');
        self::assertIsString($f['install_id']);
        $this->expectException(ValidationException::class);
        Validation::assertUuidV4('install_id', $f['install_id']);
    }

    public function testIdempotencyKeyTooShort(): void
    {
        $f = self::fixture('notification-request.idempotency-key-too-short.pattern.json');
        self::assertIsString($f['idempotency_key']);
        $this->expectException(ValidationException::class);
        new NotifyOptions(idempotencyKey: $f['idempotency_key']);
    }

    public function testCollapseIdTooLong(): void
    {
        $f = self::fixture('notification-request.collapse-id-too-long.maxLength.json');
        self::assertIsString($f['collapse_id']);
        $this->expectException(ValidationException::class);
        new NotifyOptions(collapseId: $f['collapse_id']);
    }

    public function testTtlNegative(): void
    {
        $f = self::fixture('notification-request.ttl-negative.minimum.json');
        self::assertIsInt($f['ttl']);
        $this->expectException(ValidationException::class);
        new NotifyOptions(ttl: $f['ttl']);
    }

    /** @return iterable<string, array{string}> Payload-level invalid fixtures */
    public static function payloadFixtures(): iterable
    {
        $names = [
            'notification-request.event-missing-type.required.json',
            'notification-request.event-type-bad-format.pattern.json',
            'notification-request.payload-missing-event.required.json',
            'notification-request.payload-missing-ui.required.json',
            'notification-request.ui-missing-body.required.json',
            'notification-request.deeplink-missing-target.required.json',
            'notification-request.deeplink-param-not-string.additionalProperties-type.json',
            'notification-request.payload-3501-bytes.x-maxEncodedBytes.json',
        ];
        foreach ($names as $name) {
            yield $name => [$name];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('payloadFixtures')]
    public function testPayloadFixturesRejected(string $name): void
    {
        $f = self::fixture($name);
        self::assertIsArray($f['payload']);
        $this->expectException(ValidationException::class);
        Payload::fromArray($f['payload']);
    }
}
