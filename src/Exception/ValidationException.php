<?php

declare(strict_types=1);

namespace PushCenter\Client\Exception;

/**
 * Request rejected as invalid — either by the client-side pre-flight
 * validation (statusCode 0, before any network I/O) or by the gateway
 * (`422 validation_error`). Never retried: the request itself is wrong.
 */
final class ValidationException extends ApiException
{
    public static function clientSide(string $message): self
    {
        return new self(0, 'validation_error', $message);
    }
}
