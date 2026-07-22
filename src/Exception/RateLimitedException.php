<?php

declare(strict_types=1);

namespace PushCenter\Client\Exception;

/**
 * HTTP 429 from the gateway. The contract defines TWO distinct codes
 * (SPEC-API §3): `rate_limited` (request-rate window, MAY be retried with
 * backoff honouring Retry-After — enable RetryConfig::$retryOn429) and
 * `limit_exceeded` (project device-capacity ceiling — retrying is
 * pointless and is never done, even with $retryOn429). The actual
 * `error.code` from the envelope is preserved in $errorCode.
 */
final class RateLimitedException extends ApiException
{
    /** @param int|null $retryAfterSeconds Parsed Retry-After header, when present */
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        string $errorCode = 'rate_limited',
    ) {
        parent::__construct(429, $errorCode, $message);
    }

    /** True for the capacity-ceiling code where a retry can never succeed. */
    public function isLimitExceeded(): bool
    {
        return $this->errorCode === 'limit_exceeded';
    }
}
