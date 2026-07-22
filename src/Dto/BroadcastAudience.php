<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

/**
 * `to.broadcast.audience` (SPEC-API §4.4) — which devices count as
 * recipients, decided by the presence of a user binding in the gateway
 * registry.
 */
enum BroadcastAudience: string
{
    case All = 'all';
    case Authenticated = 'authenticated';
    case Guest = 'guest';
}
