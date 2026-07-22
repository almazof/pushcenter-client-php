<?php

declare(strict_types=1);

namespace PushCenter\Client\Exception;

/**
 * Network-level failure: connection refused, DNS, timeout, broken response.
 * Always retryable per SPEC-API §5 rule 1 (same idempotency_key).
 */
final class TransportException extends PushCenterException
{
    public function __construct(
        string $message,
        public readonly bool $timedOut = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
