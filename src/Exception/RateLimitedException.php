<?php

declare(strict_types=1);

namespace PushCenter\Client\Exception;

/**
 * `429 rate_limited` from the gateway. Not retried by default (the caller
 * owns backpressure policy); enable RetryConfig::$retryOn429 to opt in —
 * the contract explicitly allows a same-key retry with backoff.
 */
final class RateLimitedException extends ApiException
{
    /** @param int|null $retryAfterSeconds Parsed Retry-After header, when present */
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct(429, 'rate_limited', $message);
    }
}
