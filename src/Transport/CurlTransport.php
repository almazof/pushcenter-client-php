<?php

declare(strict_types=1);

namespace PushCenter\Client\Transport;

use PushCenter\Client\Exception\TransportException;

/**
 * Built-in zero-dependency transport for projects without a PSR-18 client
 * (plain-curl codebases). Requires ext-curl.
 */
final class CurlTransport implements TransportInterface
{
    public function __construct(
        private readonly float $timeoutSeconds = 10.0,
        private readonly float $connectTimeoutSeconds = 5.0,
    ) {
        if (!\extension_loaded('curl')) {
            throw new \RuntimeException(
                'ext-curl is not loaded; install it or pass a Psr18Transport to PushCenterClient.'
            );
        }
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $headerLines = [];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        $ch = curl_init($request->url);
        if ($ch === false) {
            throw new TransportException('curl_init failed for ' . $request->url);
        }

        try {
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $request->method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_TIMEOUT_MS => (int) round($this->timeoutSeconds * 1000),
                CURLOPT_CONNECTTIMEOUT_MS => (int) round($this->connectTimeoutSeconds * 1000),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }

                    return strlen($line);
                },
            ]);
            if ($request->body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request->body);
            }

            $body = curl_exec($ch);
            if (!is_string($body)) {
                $errno = curl_errno($ch);

                throw new TransportException(
                    sprintf('curl error %d: %s (%s %s)', $errno, curl_error($ch), $request->method, $request->url),
                    timedOut: $errno === CURLE_OPERATION_TIMEDOUT,
                );
            }

            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            return new TransportResponse((int) $status, $body, $responseHeaders);
        } finally {
            curl_close($ch);
        }
    }
}
