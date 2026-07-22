<?php

declare(strict_types=1);

namespace PushCenter\Client;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PushCenter\Client\Dto\BindUserResult;
use PushCenter\Client\Dto\HealthResult;
use PushCenter\Client\Dto\NotifyOptions;
use PushCenter\Client\Dto\NotifyResult;
use PushCenter\Client\Dto\ProjectHealthResult;
use PushCenter\Client\Dto\QueueStats;
use PushCenter\Client\Dto\RegisterDeviceRequest;
use PushCenter\Client\Dto\RegisterDeviceResult;
use PushCenter\Client\Dto\TokenTarget;
use PushCenter\Client\Dto\UnbindUserResult;
use PushCenter\Client\Exception\ApiException;
use PushCenter\Client\Exception\PushCenterException;
use PushCenter\Client\Exception\RateLimitedException;
use PushCenter\Client\Exception\TransportException;
use PushCenter\Client\Exception\ValidationException;
use PushCenter\Client\Payload\Payload;
use PushCenter\Client\Retry\SleeperInterface;
use PushCenter\Client\Retry\UsleepSleeper;
use PushCenter\Client\Transport\CurlTransport;
use PushCenter\Client\Transport\TransportInterface;
use PushCenter\Client\Transport\TransportRequest;
use PushCenter\Client\Transport\TransportResponse;

/**
 * Thin facade over the PushCenter gateway HTTP API v1 (SPEC-API.md).
 *
 * Retry contract (SPEC-API §5 rule 1): 5xx and network failures are
 * retried with the SAME idempotency_key and exponential backoff; 4xx are
 * never retried; 429 is surfaced as RateLimitedException unless
 * RetryConfig::$retryOn429 opts in.
 *
 * fireAndForget mode: enqueueing a push must never fail a business
 * operation of the calling project. A client obtained via
 * `$client->fireAndForget()` returns `false` from notify*() instead of
 * throwing, logging the failure to the PSR-3 logger (NullLogger default).
 */
final class PushCenterClient
{
    private readonly TransportInterface $transport;

    private readonly LoggerInterface $logger;

    private readonly SleeperInterface $sleeper;

    private bool $fireAndForget = false;

    public function __construct(
        private readonly ClientConfig $config,
        ?TransportInterface $transport = null,
        ?LoggerInterface $logger = null,
        ?SleeperInterface $sleeper = null,
    ) {
        $this->transport = $transport
            ?? new CurlTransport($config->timeoutSeconds, $config->connectTimeoutSeconds);
        $this->logger = $logger ?? new NullLogger();
        $this->sleeper = $sleeper ?? new UsleepSleeper();
    }

    /** Returns a copy of the client with fire-and-forget mode toggled. */
    public function fireAndForget(bool $enabled = true): self
    {
        $clone = clone $this;
        $clone->fireAndForget = $enabled;

        return $clone;
    }

    // ---- devices ---------------------------------------------------------

    public function registerDevice(RegisterDeviceRequest $request): RegisterDeviceResult
    {
        $data = $this->postJson('/v1/devices/register', $request->toArray());

        return new RegisterDeviceResult((bool) ($data['registered'] ?? false));
    }

    public function bindUser(string $installId, string $userId): BindUserResult
    {
        Validation::assertUuidV4('install_id', $installId);
        Validation::assertUserId($userId);

        $data = $this->postJson('/v1/devices/bind', [
            'install_id' => $installId,
            'user_id' => $userId,
        ]);

        return new BindUserResult((bool) ($data['bound'] ?? false));
    }

    public function unbindUser(string $installId): UnbindUserResult
    {
        Validation::assertUuidV4('install_id', $installId);

        $data = $this->postJson('/v1/devices/unbind', ['install_id' => $installId]);

        return new UnbindUserResult((bool) ($data['unbound'] ?? false));
    }

    // ---- notifications ---------------------------------------------------

    public function notifyUser(string $userId, Payload $payload, ?NotifyOptions $options = null): NotifyResult|false
    {
        Validation::assertUserId($userId);
        $options ??= new NotifyOptions();

        $to = ['user_id' => $userId];
        if ($options->locale !== null) {
            $to['locale'] = $options->locale;
        }

        return $this->notify($to, $payload, $options);
    }

    public function notifyInstall(string $installId, Payload $payload, ?NotifyOptions $options = null): NotifyResult|false
    {
        Validation::assertUuidV4('install_id', $installId);
        $options ??= new NotifyOptions();
        if ($options->locale !== null) {
            throw ValidationException::clientSide('to.locale filter is valid only with user_id addressing');
        }

        return $this->notify(['install_id' => $installId], $payload, $options);
    }

    /** @param list<TokenTarget> $targets 1..500 direct token recipients */
    public function notifyTokens(array $targets, Payload $payload, ?NotifyOptions $options = null): NotifyResult|false
    {
        if ($targets === [] || count($targets) > 500) {
            throw ValidationException::clientSide('to.tokens must contain 1..500 targets');
        }
        $tokens = [];
        foreach ($targets as $target) {
            $tokens[] = $target->toArray();
        }
        $options ??= new NotifyOptions();
        if ($options->locale !== null) {
            throw ValidationException::clientSide('to.locale filter is valid only with user_id addressing');
        }

        return $this->notify(['tokens' => $tokens], $payload, $options);
    }

    // ---- health ----------------------------------------------------------

    public function health(): HealthResult
    {
        $data = $this->requestJson('GET', '/v1/health', null, authenticated: false);

        $queue = null;
        if (isset($data['queue']) && is_array($data['queue'])) {
            $queue = new QueueStats(
                depth: self::intField($data['queue'], 'depth'),
                delayed: self::intField($data['queue'], 'delayed'),
                processing: self::intField($data['queue'], 'processing'),
                dlq: self::intField($data['queue'], 'dlq'),
            );
        }

        return new HealthResult(self::stringField($data, 'status'), $queue);
    }

    public function projectHealth(bool $deep = false): ProjectHealthResult
    {
        $data = $this->requestJson('GET', '/v1/projects/self/health' . ($deep ? '?deep=1' : ''), null);

        return new ProjectHealthResult(
            status: self::stringField($data, 'status'),
            mode: isset($data['mode']) && is_string($data['mode']) ? $data['mode'] : null,
            apnsStatus: self::transportStatus($data, 'apns'),
            fcmStatus: self::transportStatus($data, 'fcm'),
        );
    }

    // ---- internals -------------------------------------------------------

    /** @param array<string, mixed> $to */
    private function notify(array $to, Payload $payload, NotifyOptions $options): NotifyResult|false
    {
        // The key is fixed BEFORE the retry loop: every attempt of one
        // logical send carries the same idempotency_key (contract §5.1).
        $idempotencyKey = $options->idempotencyKey ?? IdempotencyKey::random();

        $body = [
            'idempotency_key' => $idempotencyKey,
            'to' => $to,
            'payload' => $payload->toArray(),
        ];
        if ($options->collapseId !== null) {
            $body['collapse_id'] = $options->collapseId;
        }
        if ($options->ttl !== null) {
            $body['ttl'] = $options->ttl;
        }

        try {
            $data = $this->postJson('/v1/notifications', $body);

            return new NotifyResult(self::stringField($data, 'status'), $idempotencyKey);
        } catch (PushCenterException $e) {
            if (!$this->fireAndForget) {
                throw $e;
            }
            $this->logger->error('PushCenter notify failed (fire-and-forget): {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
                'idempotency_key' => $idempotencyKey,
            ]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $body): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw ValidationException::clientSide('request body is not JSON-serializable: ' . json_last_error_msg());
        }

        return $this->requestJson('POST', $path, $json);
    }

    /** @return array<string, mixed> */
    private function requestJson(string $method, string $path, ?string $json, bool $authenticated = true): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($authenticated) {
            $headers['X-PushCenter-Key'] = $this->config->apiKey;
        }
        if ($json !== null) {
            $headers['Content-Type'] = 'application/json; charset=utf-8';
        }

        $request = new TransportRequest($method, $this->config->baseUrl . $path, $headers, $json);
        $retry = $this->config->retry;

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                $response = $this->transport->send($request);
                if ($response->statusCode >= 200 && $response->statusCode < 300) {
                    return $this->decodeBody($response);
                }

                throw $this->apiException($response);
            } catch (TransportException|ApiException $e) {
                if ($attempt >= $retry->maxAttempts || !$this->isRetryable($e)) {
                    throw $e;
                }
                $delayMs = $retry->delayMsAfter($attempt);
                $this->logger->warning('PushCenter request failed, retrying in {delay_ms}ms: {message}', [
                    'delay_ms' => $delayMs,
                    'message' => $e->getMessage(),
                    'attempt' => $attempt,
                    'path' => $path,
                ]);
                $this->sleeper->sleepMs($delayMs);
            }
        }
    }

    private function isRetryable(PushCenterException $e): bool
    {
        if ($e instanceof TransportException) {
            return true;
        }
        if ($e instanceof RateLimitedException) {
            return $this->config->retry->retryOn429;
        }

        return $e instanceof ApiException && $e->isServerError();
    }

    private function apiException(TransportResponse $response): ApiException
    {
        $code = 'server_error';
        $message = 'HTTP ' . $response->statusCode;
        $decoded = json_decode($response->body, true);
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $envelope = $decoded['error'];
            if (isset($envelope['code']) && is_string($envelope['code'])) {
                $code = $envelope['code'];
            }
            if (isset($envelope['message']) && is_string($envelope['message'])) {
                $message = $envelope['message'];
            }
        }

        if ($response->statusCode === 429) {
            $retryAfter = $response->header('Retry-After');
            return new RateLimitedException(
                $message,
                $retryAfter !== null && ctype_digit($retryAfter) ? (int) $retryAfter : null,
            );
        }
        if ($code === 'validation_error') {
            return new ValidationException($response->statusCode, $code, $message);
        }

        return new ApiException($response->statusCode, $code, $message);
    }

    /** @return array<string, mixed> */
    private function decodeBody(TransportResponse $response): array
    {
        $decoded = json_decode($response->body, true);
        if (!is_array($decoded)) {
            throw new TransportException(
                'Gateway returned a non-JSON body with status ' . $response->statusCode
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private static function stringField(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        return is_string($value) ? $value : '';
    }

    /** @param array<mixed> $data */
    private static function intField(array $data, string $field): int
    {
        $value = $data[$field] ?? null;

        return is_int($value) ? $value : 0;
    }

    /** @param array<string, mixed> $data */
    private static function transportStatus(array $data, string $key): ?string
    {
        $block = $data[$key] ?? null;
        if (is_array($block) && isset($block['status']) && is_string($block['status'])) {
            return $block['status'];
        }

        return null;
    }
}
