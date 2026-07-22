<?php

declare(strict_types=1);

namespace PushCenter\Client;

use PushCenter\Client\Retry\RetryConfig;

final class ClientConfig
{
    public readonly string $baseUrl;

    public readonly RetryConfig $retry;

    public function __construct(
        string $baseUrl,
        public readonly string $apiKey,
        ?RetryConfig $retry = null,
        public readonly float $timeoutSeconds = 10.0,
        public readonly float $connectTimeoutSeconds = 5.0,
    ) {
        if (preg_match('#^https?://#', $baseUrl) !== 1) {
            throw new \InvalidArgumentException("baseUrl must be an http(s) URL, got '{$baseUrl}'");
        }
        if ($apiKey === '') {
            throw new \InvalidArgumentException('apiKey must not be empty');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->retry = $retry ?? new RetryConfig();
    }
}
