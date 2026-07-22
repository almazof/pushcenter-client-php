<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

use PushCenter\Client\Validation;

/** One direct-token recipient of `to.tokens[]` (SPEC-API §4.4). */
final class TokenTarget
{
    public function __construct(
        public readonly string $token,
        public readonly Platform $platform,
    ) {
        Validation::assertDeviceToken('token', $token);
    }

    /** @return array{token: string, platform: string} */
    public function toArray(): array
    {
        return ['token' => $this->token, 'platform' => $this->platform->value];
    }
}
