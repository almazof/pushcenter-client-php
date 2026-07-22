<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

/**
 * `202` body of POST /v1/notifications: status `enqueued` | `deduplicated`.
 * `deduplicated` is NOT an error — the same idempotency_key was already
 * accepted inside the dedup window (at-least-once semantics).
 */
final class NotifyResult
{
    public const STATUS_ENQUEUED = 'enqueued';
    public const STATUS_DEDUPLICATED = 'deduplicated';

    public function __construct(
        public readonly string $status,
        public readonly string $idempotencyKey,
    ) {
    }

    public function isDeduplicated(): bool
    {
        return $this->status === self::STATUS_DEDUPLICATED;
    }
}
