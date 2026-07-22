<?php

declare(strict_types=1);

namespace PushCenter\Client\Payload;

use PushCenter\Client\Exception\ValidationException;

/**
 * Validated, immutable `payload` of POST /v1/notifications
 * (SPEC-PAYLOAD.md §1). Built via PayloadBuilder or fromArray(); both
 * paths enforce the required fields and the 3500-byte schema limit
 * (x-maxEncodedBytes) with the SAME measurement algorithm as the gateway:
 * strlen(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).
 */
final class Payload
{
    public const MAX_ENCODED_BYTES = 3500;

    private const EVENT_TYPE_PATTERN = '/^[a-z][a-z0-9_.]*$/';

    /** @param array<mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @param array<mixed> $payload Raw payload array per SPEC-PAYLOAD §1
     * @throws ValidationException on any client-checkable contract violation
     */
    public static function fromArray(array $payload): self
    {
        $event = $payload['event'] ?? null;
        if (!is_array($event)) {
            throw ValidationException::clientSide('payload.event is required and must be an object');
        }
        $type = $event['type'] ?? null;
        if (!is_string($type) || $type === '' || strlen($type) > 128
            || preg_match(self::EVENT_TYPE_PATTERN, $type) !== 1
        ) {
            throw ValidationException::clientSide(
                'payload.event.type is required and must match ^[a-z][a-z0-9_.]*$ (1..128)'
            );
        }
        $id = $event['id'] ?? null;
        if (!is_string($id) || $id === '' || strlen($id) > 128) {
            throw ValidationException::clientSide('payload.event.id is required (string 1..128)');
        }
        if (isset($event['data']) && !is_array($event['data'])) {
            throw ValidationException::clientSide('payload.event.data must be an object');
        }

        $ui = $payload['ui'] ?? null;
        if (!is_array($ui)) {
            throw ValidationException::clientSide('payload.ui is required and must be an object');
        }
        if (!isset($ui['title']) || !is_string($ui['title']) || strlen($ui['title']) > 256) {
            throw ValidationException::clientSide('payload.ui.title is required (string 0..256)');
        }
        if (!isset($ui['body']) || !is_string($ui['body']) || strlen($ui['body']) > 1024) {
            throw ValidationException::clientSide('payload.ui.body is required (string 0..1024)');
        }
        if (isset($ui['subtitle']) && (!is_string($ui['subtitle']) || strlen($ui['subtitle']) > 256)) {
            throw ValidationException::clientSide('payload.ui.subtitle must be a string 0..256');
        }

        if (array_key_exists('deeplink', $payload)) {
            $deeplink = $payload['deeplink'];
            if (!is_array($deeplink)) {
                throw ValidationException::clientSide('payload.deeplink must be an object');
            }
            if (!isset($deeplink['target']) || !is_string($deeplink['target']) || strlen($deeplink['target']) > 128) {
                throw ValidationException::clientSide(
                    'payload.deeplink.target is required inside deeplink (string 0..128)'
                );
            }
            if (isset($deeplink['params'])) {
                if (!is_array($deeplink['params'])) {
                    throw ValidationException::clientSide('payload.deeplink.params must be an object');
                }
                foreach ($deeplink['params'] as $key => $value) {
                    if (!is_string($value)) {
                        throw ValidationException::clientSide(
                            "payload.deeplink.params.{$key} must be a string (contract: string values only)"
                        );
                    }
                }
            }
        }

        $bytes = self::measureEncodedBytes($payload);
        if ($bytes > self::MAX_ENCODED_BYTES) {
            throw ValidationException::clientSide(sprintf(
                'payload is %d bytes; the contract schema limit is %d bytes (x-maxEncodedBytes)',
                $bytes,
                self::MAX_ENCODED_BYTES,
            ));
        }

        return new self($payload);
    }

    /** @return array<mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function encodedBytes(): int
    {
        return self::measureEncodedBytes($this->data);
    }

    /**
     * Gateway-parity size measurement: UTF-8 JSON bytes without unicode
     * or slash escaping (SPEC-PAYLOAD §5 rule 1).
     *
     * @param array<mixed> $payload
     */
    public static function measureEncodedBytes(array $payload): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw ValidationException::clientSide('payload is not JSON-serializable: ' . json_last_error_msg());
        }

        return strlen($json);
    }
}
