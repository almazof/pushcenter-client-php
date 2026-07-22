<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

use PushCenter\Client\Exception\ValidationException;

/** One direct-token recipient of `to.tokens[]` (SPEC-API §4.4). */
final class TokenTarget
{
    public function __construct(
        public readonly string $token,
        public readonly Platform $platform,
    ) {
        if ($token === '' || strlen($token) > 4096) {
            throw ValidationException::clientSide('token must be 1..4096 characters');
        }
    }

    /** @return array{token: string, platform: string} */
    public function toArray(): array
    {
        return ['token' => $this->token, 'platform' => $this->platform->value];
    }
}
