<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Support;

use PushCenter\Client\Exception\TransportException;
use PushCenter\Client\Transport\TransportInterface;
use PushCenter\Client\Transport\TransportRequest;
use PushCenter\Client\Transport\TransportResponse;

/**
 * Scripted transport: a queue of responses/exceptions, records every
 * request for assertions (retry counts, identical idempotency keys).
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<TransportRequest> */
    public array $requests = [];

    /** @var list<TransportResponse|TransportException> */
    private array $script = [];

    public function willRespond(TransportResponse|TransportException ...$outcomes): void
    {
        foreach ($outcomes as $outcome) {
            $this->script[] = $outcome;
        }
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $this->requests[] = $request;
        $outcome = array_shift($this->script);
        if ($outcome === null) {
            throw new \LogicException('FakeTransport script exhausted');
        }
        if ($outcome instanceof TransportException) {
            throw $outcome;
        }

        return $outcome;
    }

    /** @return array<string, mixed> Decoded JSON body of request #$index */
    public function requestBody(int $index): array
    {
        $body = $this->requests[$index]->body ?? null;
        if ($body === null) {
            throw new \LogicException("Request #{$index} has no body");
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        \assert(is_array($decoded));

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public static function json(int $status, string $body): TransportResponse
    {
        return new TransportResponse($status, $body, ['content-type' => 'application/json']);
    }
}
