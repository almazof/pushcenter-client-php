<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

/**
 * GET /v1/projects/self/health (project-health-response.schema.json).
 * Per-transport statuses: ok | failed | degraded | not_configured.
 */
final class ProjectHealthResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $mode = null,
        public readonly ?string $apnsStatus = null,
        public readonly ?string $fcmStatus = null,
    ) {
    }

    public function isOk(): bool
    {
        return $this->status === 'ok';
    }
}
