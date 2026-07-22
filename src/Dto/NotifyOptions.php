<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

use PushCenter\Client\Exception\ValidationException;
use PushCenter\Client\Validation;

/**
 * Optional knobs of POST /v1/notifications (SPEC-API §4.4).
 *
 * - $locale: device-locale filter, valid only with user_id addressing;
 * - $collapseId: apns-collapse-id / FCM collapse_key;
 * - $ttl: seconds 0..2419200; gateway default when omitted is 0
 *   ("deliver now or drop");
 * - $idempotencyKey: caller-provided key; when null the client generates
 *   a random one per logical send (and reuses it across retries).
 */
final class NotifyOptions
{
    public function __construct(
        public readonly ?string $locale = null,
        public readonly ?string $collapseId = null,
        public readonly ?int $ttl = null,
        public readonly ?string $idempotencyKey = null,
    ) {
        if ($collapseId !== null) {
            Validation::assertCollapseId($collapseId);
        }
        if ($ttl !== null && ($ttl < 0 || $ttl > 2_419_200)) {
            throw ValidationException::clientSide('ttl must be within 0..2419200 seconds');
        }
        if ($idempotencyKey !== null) {
            Validation::assertIdempotencyKey($idempotencyKey);
        }
    }
}
