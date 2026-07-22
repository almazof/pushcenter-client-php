<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PushCenter\Client\ClientConfig;
use PushCenter\Client\Dto\BroadcastAudience;
use PushCenter\Client\Dto\BroadcastFilters;
use PushCenter\Client\Dto\NotifyOptions;
use PushCenter\Client\Dto\Platform;
use PushCenter\Client\Dto\RegisterDeviceRequest;
use PushCenter\Client\Dto\TokenTarget;
use PushCenter\Client\Exception\ValidationException;
use PushCenter\Client\Payload\Payload;
use PushCenter\Client\PushCenterClient;
use PushCenter\Client\Retry\RetryConfig;
use PushCenter\Client\Tests\Support\ContractPaths;
use PushCenter\Client\Tests\Support\FakeTransport;
use PushCenter\Client\Tests\Support\RecordingSleeper;
use PushCenter\Client\Validation;

/**
 * Client-side pre-flight validation must reject every invalid contract
 * fixture whose rule IS checkable on the client. The data provider
 * enumerates `contract/fixtures/invalid/` DYNAMICALLY: a new fixture
 * lands in this suite automatically and fails until it is either
 * rejected by the client or explicitly classified as server-side-only
 * below — contract drift can never pass silently.
 */
final class InvalidFixtureRejectionTest extends TestCase
{
    private const VALID_UUID = '3f2a1b6c-9d4e-4f7a-8b2c-1e5d6f7a8b9c';

    /**
     * Rules only the gateway can check, or violations the typed client
     * API cannot even express. Each entry is a deliberate decision:
     * - missing-* / *-not-string / *-not-integer: required-ness and JSON
     *   types are enforced by PHP's type system — the violation is
     *   unrepresentable through the client API;
     * - unknown-field: additionalProperties on the full body is the
     *   gateway's JSON-Schema job, the client never emits extra fields;
     * - locale patterns (BCP-47): left to the gateway by design;
     * - to.* oneOf addressing: the client offers one method per mode,
     *   mixed/empty addressing is unrepresentable;
     * - error-response.* / health-response.* / metrics-response.*: RESPONSE
     *   schemas — the client consumes them leniently (forward
     *   compatibility, §5.5).
     *
     * @var list<string>
     */
    private const SERVER_SIDE_ONLY = [
        'device-bind-request.missing-install-id.required.json',
        'device-bind-request.missing-user-id.required.json',
        'device-bind-request.unknown-field.additionalProperties.json',
        'device-bind-request.user-id-not-string.type.json',
        'device-register-request.locale-bad-format.pattern.json',
        'device-register-request.missing-device-token.required.json',
        'device-register-request.missing-install-id.required.json',
        'device-register-request.missing-platform.required.json',
        'device-register-request.unknown-field.additionalProperties.json',
        'device-register-request.user-id-not-string.type.json',
        'device-unbind-request.missing-install-id.required.json',
        'device-unbind-request.unknown-field.additionalProperties.json',
        'error-response.code-bad-format.pattern.json',
        'error-response.missing-code.required.json',
        'error-response.missing-error.required.json',
        'error-response.missing-message.required.json',
        'error-response.unknown-field.additionalProperties.json',
        'health-response.queue-missing-dlq.required.json',
        'health-response.queue-negative-depth.minimum.json',
        'metrics-response.broadcast-missing-batches.required.json',
        'metrics-response.broadcast-negative-aborted.minimum.json',
        'metrics-response.broadcast-negative-devices.minimum.json',
        'notification-request.missing-idempotency-key.required.json',
        'notification-request.missing-payload.required.json',
        'notification-request.missing-to.required.json',
        'notification-request.to-broadcast-unknown-filter.additionalProperties.json',
        'notification-request.to-broadcast-with-install-id.oneOf.json',
        'notification-request.to-broadcast-with-tokens.oneOf.json',
        'notification-request.to-broadcast-with-user-id.oneOf.json',
        'notification-request.to-empty-object.oneOf.json',
        'notification-request.to-locale-bad-format.pattern.json',
        'notification-request.to-token-without-platform.required.json',
        'notification-request.to-two-addressing-modes.oneOf.json',
        'notification-request.ttl-not-integer.type.json',
        'notification-request.unknown-field.additionalProperties.json',
    ];

    /** @return iterable<string, array{string}> every invalid fixture not classified server-side-only */
    public static function clientCheckableFixtures(): iterable
    {
        $paths = glob(ContractPaths::fixturesDir() . '/invalid/*.json');
        self::assertNotFalse($paths);
        self::assertNotEmpty($paths, 'no invalid fixtures found — contract checkout broken?');
        foreach ($paths as $path) {
            $name = basename($path);
            if (!in_array($name, self::SERVER_SIDE_ONLY, true)) {
                yield $name => [$name];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clientCheckableFixtures')]
    public function testFixtureIsRejectedClientSide(string $name): void
    {
        $fixture = ContractPaths::fixture('invalid/' . $name);

        try {
            $this->replayThroughClientApi($name, $fixture);
            self::fail(
                "Invalid fixture {$name} was NOT rejected by client-side validation. " .
                'Either add the missing check or classify the fixture in SERVER_SIDE_ONLY with a reason.'
            );
        } catch (ValidationException|\ValueError) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Feeds the fixture into the same typed API a caller would use; each
     * request schema has one generic replay so future fixtures of a known
     * schema are picked up without code changes.
     *
     * @param array<string, mixed> $fixture
     */
    private function replayThroughClientApi(string $name, array $fixture): void
    {
        if (str_starts_with($name, 'device-register-request.')) {
            new RegisterDeviceRequest(
                installId: self::str($fixture, 'install_id', self::VALID_UUID),
                deviceToken: self::str($fixture, 'device_token', 'token'),
                platform: Platform::from(self::str($fixture, 'platform', 'ios')),
                userId: isset($fixture['user_id']) && is_string($fixture['user_id']) ? $fixture['user_id'] : null,
                appType: isset($fixture['app_type']) && is_string($fixture['app_type']) ? $fixture['app_type'] : null,
            );

            return;
        }
        if (str_starts_with($name, 'device-bind-request.')) {
            Validation::assertUuidV4('install_id', self::str($fixture, 'install_id', self::VALID_UUID));
            Validation::assertUserId(self::str($fixture, 'user_id', 'user-1'));

            return;
        }
        if (str_starts_with($name, 'device-unbind-request.')) {
            Validation::assertUuidV4('install_id', self::str($fixture, 'install_id', self::VALID_UUID));

            return;
        }
        if (str_starts_with($name, 'notification-request.')) {
            $this->replayNotification($fixture);

            return;
        }
        self::fail("No client-side replay for fixture {$name} — add a handler or a SERVER_SIDE_ONLY entry.");
    }

    /** @param array<string, mixed> $fixture */
    private function replayNotification(array $fixture): void
    {
        $payloadRaw = $fixture['payload'] ?? null;
        if (!is_array($payloadRaw)) {
            throw ValidationException::clientSide('fixture payload is not an object');
        }
        $payload = Payload::fromArray($payloadRaw);

        new NotifyOptions(
            collapseId: isset($fixture['collapse_id']) && is_string($fixture['collapse_id'])
                ? $fixture['collapse_id'] : null,
            ttl: isset($fixture['ttl']) && is_int($fixture['ttl']) ? $fixture['ttl'] : null,
            idempotencyKey: isset($fixture['idempotency_key']) && is_string($fixture['idempotency_key'])
                ? $fixture['idempotency_key'] : null,
        );

        $to = $fixture['to'] ?? null;

        if (is_array($to) && array_key_exists('broadcast', $to) && is_array($to['broadcast'])) {
            $broadcast = $to['broadcast'];
            new BroadcastFilters(
                platform: isset($broadcast['platform']) && is_string($broadcast['platform'])
                    ? Platform::from($broadcast['platform']) : null,
                locale: isset($broadcast['locale']) && is_string($broadcast['locale'])
                    ? $broadcast['locale'] : null,
                appType: isset($broadcast['app_type']) && is_string($broadcast['app_type'])
                    ? $broadcast['app_type'] : null,
                audience: isset($broadcast['audience']) && is_string($broadcast['audience'])
                    ? BroadcastAudience::from($broadcast['audience']) : null,
            );

            return;
        }

        if (is_array($to) && array_key_exists('tokens', $to) && is_array($to['tokens'])) {
            $targets = [];
            foreach ($to['tokens'] as $raw) {
                self::assertIsArray($raw);
                $targets[] = new TokenTarget(
                    self::str($raw, 'token', 'fcm-token'),
                    Platform::from(self::str($raw, 'platform', 'ios')),
                );
            }
            // notifyTokens() owns the 1..500 cardinality rule; the fake
            // transport answers 202 so only client validation can throw.
            $transport = new FakeTransport();
            $transport->willRespond(FakeTransport::json(202, '{"status":"enqueued"}'));
            $client = new PushCenterClient(
                new ClientConfig('http://gateway.test', 'k', RetryConfig::none()),
                $transport,
                sleeper: new RecordingSleeper(),
            );
            $client->notifyTokens($targets, $payload);
        }
    }

    /** @param array<mixed> $data */
    private static function str(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
