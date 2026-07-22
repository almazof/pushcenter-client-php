<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

/** GET /v1/health (health-response.schema.json). Queue block is optional. */
final class HealthResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?QueueStats $queue = null,
    ) {
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }
}
