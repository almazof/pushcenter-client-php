<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PushCenter\Client\ClientConfig;
use PushCenter\Client\Dto\BroadcastAudience;
use PushCenter\Client\Dto\BroadcastFilters;
use PushCenter\Client\Dto\NotifyOptions;
use PushCenter\Client\Dto\Platform;
use PushCenter\Client\Dto\RegisterDeviceRequest;
use PushCenter\Client\Exception\ApiException;
use PushCenter\Client\Exception\ValidationException;
use PushCenter\Client\IdempotencyKey;
use PushCenter\Client\Payload\PayloadBuilder;
use PushCenter\Client\PushCenterClient;
use PushCenter\Client\Retry\RetryConfig;

/**
 * End-to-end contract tests against a LIVE gateway. They are NOT part of the
 * default suite: running them needs a local checkout of the PushCenter gateway
 * service, which is not distributed with this client.
 *
 * Given one, the suite boots `php -S` from the gateway's `public/` with a
 * throwaway stub-project config (provider mode `stub`, no real APNs/FCM
 * credentials), applies migrations idempotently to a dedicated test database
 * and uses a dedicated Redis logical db. Paths and connection settings come
 * from the PUSHCENTER_GATEWAY_DIR / PUSHCENTER_TEST_* environment variables
 * declared in phpunit.xml.dist.
 *
 * @group integration
 */
#[Group('integration')]
final class LiveGatewayContractTest extends TestCase
{
    private const API_KEY = 'client-php-int-key-000000000001';

    private static string $baseUrl;
    private static string $projectsDir;
    /** @var resource|null */
    private static $serverProcess = null;

    private PushCenterClient $client;

    public static function setUpBeforeClass(): void
    {
        $gatewayDir = self::env('PUSHCENTER_GATEWAY_DIR', dirname(__DIR__, 3) . '/gateway');
        $gatewayReal = realpath($gatewayDir);
        if ($gatewayReal === false || !is_file($gatewayReal . '/public/index.php')) {
            self::fail("Gateway repo not found at {$gatewayDir}");
        }

        self::$projectsDir = sys_get_temp_dir() . '/pushcenter-client-int-' . bin2hex(random_bytes(6));
        $projectDir = self::$projectsDir . '/client-int';
        if (!mkdir($projectDir, 0700, true)) {
            self::fail('Cannot create temp projects dir');
        }
        file_put_contents($projectDir . '/project.json', json_encode([
            'name' => 'client-int',
            'api_key_hash' => 'sha256:' . hash('sha256', self::API_KEY),
            // The production broadcast window is 10/hour and the test Redis
            // is NOT flushed between runs — a realistic limit here would
            // fail the suite on its fourth run of the hour. The window
            // itself is covered by the gateway's own tests; what this suite
            // verifies is the client-to-gateway contract.
            'broadcast_rate_limit' => ['limit' => 1000, 'window_seconds' => 60],
            'apns' => [
                'mode' => 'stub',
                'key_path' => 'missing.p8',
                'key_id' => 'KEYID12345',
                'team_id' => 'TEAM123456',
                'bundle_id' => 'com.example.app',
                'environment' => 'sandbox',
            ],
            'fcm' => [
                'mode' => 'stub',
                'project_id' => 'example-project',
                'service_account_path' => 'missing.json',
            ],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $env = array_merge(self::cleanEnv(), [
            'PUSHCENTER_PROJECTS_DIR' => self::$projectsDir,
            'PUSHCENTER_DB_DSN' => self::env('PUSHCENTER_TEST_DB_DSN', 'pgsql:host=127.0.0.1;port=55432;dbname=pushcenter_test'),
            'PUSHCENTER_DB_USER' => self::env('PUSHCENTER_TEST_DB_USER', 'pushcenter'),
            'PUSHCENTER_DB_PASSWORD' => self::env('PUSHCENTER_TEST_DB_PASSWORD', 'pushcenter'),
            'PUSHCENTER_REDIS_HOST' => self::env('PUSHCENTER_TEST_REDIS_HOST', '127.0.0.1'),
            'PUSHCENTER_REDIS_PORT' => self::env('PUSHCENTER_TEST_REDIS_PORT', '56379'),
            'PUSHCENTER_REDIS_DB' => self::env('PUSHCENTER_TEST_REDIS_DB', '5'),
        ]);

        // Idempotent migrations on the dedicated test database.
        $migrate = proc_open(
            [PHP_BINARY, 'bin/migrate.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $migratePipes,
            $gatewayReal,
            $env,
        );
        if (!is_resource($migrate)) {
            self::fail('Cannot run gateway bin/migrate.php');
        }
        $migrateOut = stream_get_contents($migratePipes[1]) . stream_get_contents($migratePipes[2]);
        if (proc_close($migrate) !== 0) {
            self::fail("gateway migrate failed (is docker-compose postgres up?):\n" . $migrateOut);
        }

        $port = self::findFreePort();
        self::$baseUrl = "http://127.0.0.1:{$port}";
        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', 'public', 'public/index.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $gatewayReal,
            $env,
        );
        if (!is_resource($process)) {
            self::fail('Cannot start php -S for the gateway');
        }
        self::$serverProcess = $process;

        self::waitForHealth();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
        if (isset(self::$projectsDir) && is_dir(self::$projectsDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::$projectsDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if ($item instanceof \SplFileInfo) {
                    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
                }
            }
            rmdir(self::$projectsDir);
        }
    }

    protected function setUp(): void
    {
        $this->client = new PushCenterClient(
            new ClientConfig(self::$baseUrl, self::API_KEY, new RetryConfig(baseDelayMs: 50)),
        );
    }

    public function testFullDeviceAndNotificationLifecycle(): void
    {
        $installId = self::uuidV4();
        $userId = 'client-int-user-' . bin2hex(random_bytes(4));

        // 1. Register a guest device.
        $registered = $this->client->registerDevice(new RegisterDeviceRequest(
            installId: $installId,
            deviceToken: 'client-int-token-' . bin2hex(random_bytes(8)),
            platform: Platform::Android,
            locale: 'ru',
        ));
        self::assertTrue($registered->registered);

        // 2. Bind it to a user.
        self::assertTrue($this->client->bindUser($installId, $userId)->bound);

        // 3. notifyUser -> 202 enqueued.
        $payload = (new PayloadBuilder())
            ->event('order_created', 'evt-int-1', ['orderId' => 91])
            ->deeplink('OrderDetailsScreen', ['orderId' => '42'])
            ->ui('Заказ №1024 готов к выдаче', 'Новый заказ')
            ->build();
        $key = IdempotencyKey::deterministic('evt-int-1', 'order_created', ['run' => bin2hex(random_bytes(8))], $userId);

        $first = $this->client->notifyUser($userId, $payload, new NotifyOptions(idempotencyKey: $key));
        self::assertNotFalse($first);
        self::assertSame('enqueued', $first->status);

        // 4. Same idempotency_key again -> 202 deduplicated (still not an error).
        $second = $this->client->notifyUser($userId, $payload, new NotifyOptions(idempotencyKey: $key));
        self::assertNotFalse($second);
        self::assertTrue($second->isDeduplicated());

        // 5. Unbind stays idempotent.
        self::assertTrue($this->client->unbindUser($installId)->unbound);
        self::assertTrue($this->client->unbindUser($installId)->unbound);
    }

    public function testBroadcastIsAcceptedAndDeduplicatedByTheLiveGateway(): void
    {
        $payload = (new PayloadBuilder())
            ->event('service_announcement', 'evt-int-broadcast')
            ->ui('Плановые работы', 'С 02:00 до 03:00')
            ->build();
        $key = IdempotencyKey::deterministic(
            'evt-int-broadcast',
            'service_announcement',
            ['run' => bin2hex(random_bytes(8))],
            'broadcast',
        );

        $first = $this->client->notifyBroadcast(
            $payload,
            new BroadcastFilters(platform: Platform::Android, locale: 'ru', audience: BroadcastAudience::All),
            new NotifyOptions(idempotencyKey: $key),
        );
        self::assertNotFalse($first);
        self::assertSame('enqueued', $first->status);

        // The same key cannot start a second rollout (SPEC-API §4.4).
        $second = $this->client->notifyBroadcast(
            $payload,
            new BroadcastFilters(platform: Platform::Android, locale: 'ru', audience: BroadcastAudience::All),
            new NotifyOptions(idempotencyKey: $key),
        );
        self::assertNotFalse($second);
        self::assertTrue($second->isDeduplicated());

        // No filters at all is the whole active base — still an ordinary 202.
        $all = $this->client->notifyBroadcast($payload);
        self::assertNotFalse($all);
        self::assertSame('enqueued', $all->status);
    }

    public function testGatewayValidationErrorIsTyped(): void
    {
        // locale format is deliberately NOT pre-validated client-side:
        // the gateway JSON Schema rejects it -> typed ValidationException.
        $payload = (new PayloadBuilder())->event('x_event', 'evt-int-2')->ui('T', 'B')->build();

        try {
            $this->client->notifyUser('u1', $payload, new NotifyOptions(locale: 'not a locale!'));
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->statusCode);
            self::assertSame('validation_error', $e->errorCode);
        }
    }

    public function testUnknownInstallIdIsNotFoundWithoutRetry(): void
    {
        try {
            $this->client->bindUser(self::uuidV4(), 'nobody');
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(404, $e->statusCode);
            self::assertSame('not_found', $e->errorCode);
        }
    }

    public function testWrongApiKeyIsUnauthorized(): void
    {
        $stranger = new PushCenterClient(
            new ClientConfig(self::$baseUrl, 'wrong-key', RetryConfig::none()),
        );

        try {
            $stranger->unbindUser(self::uuidV4());
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(401, $e->statusCode);
            self::assertSame('unauthorized', $e->errorCode);
        }
    }

    public function testHealthEndpoints(): void
    {
        self::assertTrue($this->client->health()->isOk());

        $project = $this->client->projectHealth();
        self::assertSame('ok', $project->status);
        self::assertSame('ok', $project->apnsStatus);
        self::assertSame('ok', $project->fcmStatus);
    }

    // ---- plumbing --------------------------------------------------------

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @return array<string, string> */
    private static function cleanEnv(): array
    {
        $env = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value) && preg_match('/^[A-Z_][A-Z0-9_]*$/', $key) === 1) {
                $env[$key] = $value;
            }
        }
        // Let the gateway resolve its own schema directory rather than inherit
        // a path that is only meaningful inside this repository.
        unset($env['PUSHCENTER_SCHEMAS_DIR']);

        return $env;
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            self::fail("Cannot allocate a free port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if (!is_string($name) || !str_contains($name, ':')) {
            self::fail('Cannot determine the allocated port');
        }

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function waitForHealth(): void
    {
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            $body = @file_get_contents(self::$baseUrl . '/v1/health');
            if (is_string($body) && str_contains($body, '"ok"')) {
                return;
            }
            usleep(100_000);
        }
        self::fail('Gateway did not answer /v1/health within 10s (php -S failed to boot?)');
    }

    private static function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
