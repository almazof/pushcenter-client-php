<?php

declare(strict_types=1);

namespace PushCenter\Client\Retry;

/**
 * Retry policy per SPEC-API §5 rule 1: 5xx / network failures are retried
 * with the SAME idempotency_key and exponential backoff + full jitter.
 * 4xx are never retried; 429 only when $retryOn429 is enabled.
 */
final class RetryConfig
{
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly int $baseDelayMs = 200,
        public readonly int $maxDelayMs = 5_000,
        public readonly float $multiplier = 2.0,
        public readonly bool $jitter = true,
        public readonly bool $retryOn429 = false,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1');
        }
        if ($baseDelayMs < 0 || $maxDelayMs < $baseDelayMs || $multiplier < 1.0) {
            throw new \InvalidArgumentException('Invalid backoff configuration');
        }
    }

    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    /** @param int $attempt 1-based number of the attempt that just failed */
    public function delayMsAfter(int $attempt): int
    {
        $delay = min(
            (float) $this->maxDelayMs,
            $this->baseDelayMs * ($this->multiplier ** ($attempt - 1)),
        );
        if ($this->jitter) {
            $delay = $delay * (0.5 + mt_rand(0, 1000) / 2000.0); // 50–100% of the slot
        }

        return (int) round($delay);
    }
}
