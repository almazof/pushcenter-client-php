<?php

declare(strict_types=1);

namespace PushCenter\Client\Exception;

/**
 * Non-2xx gateway response with the contract error envelope
 * (SPEC-API §3): {"error": {"code": "...", "message": "..."}}.
 */
class ApiException extends PushCenterException
{
    /**
     * @param int    $statusCode HTTP status (0 for client-side validation)
     * @param string $errorCode  Machine-readable `error.code` from the envelope
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }
}
