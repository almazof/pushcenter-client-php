<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

use PushCenter\Client\Validation;

/**
 * Audience of a broadcast notification (`to.broadcast`, SPEC-API §4.4).
 *
 * Every filter is optional and they combine with AND; an instance with no
 * filters at all serializes to `{}` — the entire ACTIVE device base of the
 * project. That is a deliberate, explicit call site: `new BroadcastFilters()`
 * reads as "everyone", so nobody reaches the whole base by forgetting an
 * argument.
 *
 * Validated at construction (client-side pre-flight of the contract schema);
 * the gateway remains the authority.
 */
final class BroadcastFilters
{
    public function __construct(
        public readonly ?Platform $platform = null,
        public readonly ?string $locale = null,
        public readonly ?string $appType = null,
        public readonly ?BroadcastAudience $audience = null,
    ) {
        if ($locale !== null) {
            Validation::assertLocale($locale);
        }
        if ($appType !== null) {
            Validation::assertAppType($appType);
        }
    }

    public function isEmpty(): bool
    {
        return $this->platform === null
            && $this->locale === null
            && $this->appType === null
            && $this->audience === null;
    }

    /** @return array<string, string> the `to.broadcast` object; empty = every active device */
    public function toArray(): array
    {
        $filters = [];
        if ($this->platform !== null) {
            $filters['platform'] = $this->platform->value;
        }
        if ($this->locale !== null) {
            $filters['locale'] = $this->locale;
        }
        if ($this->appType !== null) {
            $filters['app_type'] = $this->appType;
        }
        if ($this->audience !== null) {
            $filters['audience'] = $this->audience->value;
        }

        return $filters;
    }
}
