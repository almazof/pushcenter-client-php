<?php

declare(strict_types=1);

namespace PushCenter\Client;

use PushCenter\Client\Exception\ValidationException;

/**
 * Shared client-side validators mirroring the contract schemas. Only rules
 * that are cheap and unambiguous are enforced here; the gateway remains
 * the authority (JSON Schema validation on every request).
 */
final class Validation
{
    private const UUID_V4 =
        '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/';

    private const IDEMPOTENCY_KEY = '/^[A-Za-z0-9._-]{16,128}$/';

    private function __construct()
    {
    }

    public static function assertUuidV4(string $field, string $value): void
    {
        if (preg_match(self::UUID_V4, $value) !== 1) {
            throw ValidationException::clientSide("{$field} must be a UUID v4");
        }
    }

    public static function assertUserId(string $value): void
    {
        if ($value === '' || strlen($value) > 128) {
            throw ValidationException::clientSide('user_id must be 1..128 characters');
        }
    }

    public static function assertIdempotencyKey(string $value): void
    {
        if (preg_match(self::IDEMPOTENCY_KEY, $value) !== 1) {
            throw ValidationException::clientSide(
                'idempotency_key must be 16..128 characters of [A-Za-z0-9._-]'
            );
        }
    }
}
