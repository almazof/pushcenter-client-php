<?php

declare(strict_types=1);

namespace PushCenter\Client\Transport;

final class TransportResponse
{
    /** @param array<string, string> $headers Lower-cased header name => value */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
