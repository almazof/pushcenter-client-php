<?php

declare(strict_types=1);

namespace PushCenter\Client;

/**
 * Generators for the `idempotency_key` of POST /v1/notifications.
 *
 * deterministic() mirrors Safar's PushNotificationEvent::idempotencyKey():
 * sha256 over pipe-joined canonical parts, where the event data array is
 * normalized by recursive ksort and encoded with
 * JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES. The same business event
 * always yields the same key — retries and accidental double-sends are
 * collapsed by the gateway dedup window (>= 24h).
 */
final class IdempotencyKey
{
    private function __construct()
    {
    }

    /**
     * @param string        $eventId    Sender-side event id (payload event.id)
     * @param string        $eventType  Event type (payload event.type)
     * @param array<string, mixed> $data Domain fields participating in identity
     * @param string        ...$scope   Extra identity parts (recipient id, locale, ...)
     * @return string 64-char sha256 hex — valid per contract (16..128 of [A-Za-z0-9._-])
     */
    public static function deterministic(
        string $eventId,
        string $eventType,
        array $data = [],
        string ...$scope,
    ): string {
        $json = json_encode(self::normalize($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '';
        }

        return hash('sha256', implode('|', [$eventId, $eventType, ...$scope, $json]));
    }

    /** @return string 32-char random hex — unique per call */
    public static function random(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function normalize(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalize($item);
            }
        }

        return $value;
    }
}
