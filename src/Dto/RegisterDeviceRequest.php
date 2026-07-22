<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

use PushCenter\Client\Exception\ValidationException;
use PushCenter\Client\Validation;

/**
 * Body of POST /v1/devices/register (device-register-request.schema.json).
 * Immutable; validated at construction — an invalid request never reaches
 * the wire.
 */
final class RegisterDeviceRequest
{
    public function __construct(
        public readonly string $installId,
        public readonly string $deviceToken,
        public readonly Platform $platform,
        public readonly ?string $userId = null,
        public readonly ?string $locale = null,
        public readonly ?string $appType = null,
    ) {
        Validation::assertUuidV4('install_id', $installId);
        if ($deviceToken === '' || strlen($deviceToken) > 4096) {
            throw ValidationException::clientSide('device_token must be 1..4096 characters');
        }
        if ($userId !== null && ($userId === '' || strlen($userId) > 128)) {
            throw ValidationException::clientSide('user_id must be 1..128 characters or null');
        }
        if ($appType !== null && preg_match('/^[a-z][a-z0-9_]*$/', $appType) !== 1) {
            throw ValidationException::clientSide('app_type must match ^[a-z][a-z0-9_]*$');
        }
        if ($appType !== null && strlen($appType) > 64) {
            throw ValidationException::clientSide('app_type must be at most 64 characters');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $body = [
            'install_id' => $this->installId,
            'device_token' => $this->deviceToken,
            'platform' => $this->platform->value,
        ];
        if ($this->userId !== null) {
            $body['user_id'] = $this->userId;
        }
        if ($this->locale !== null) {
            $body['locale'] = $this->locale;
        }
        if ($this->appType !== null) {
            $body['app_type'] = $this->appType;
        }

        return $body;
    }
}
