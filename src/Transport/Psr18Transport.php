<?php

declare(strict_types=1);

namespace PushCenter\Client\Transport;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use PushCenter\Client\Exception\TransportException;

/**
 * Adapter for projects that already ship a PSR-18 client (Guzzle,
 * symfony/http-client, ...). The interfaces come from psr/* packages —
 * no concrete HTTP library is pulled in by pushcenter/client itself.
 */
final class Psr18Transport implements TransportInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(TransportRequest $request): TransportResponse
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }
        if ($request->body !== null) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            $psrResponse = $this->client->sendRequest($psrRequest);
        } catch (NetworkExceptionInterface $e) {
            throw new TransportException($e->getMessage(), timedOut: true, previous: $e);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException($e->getMessage(), previous: $e);
        }

        $headers = [];
        foreach (array_keys($psrResponse->getHeaders()) as $name) {
            $headers[strtolower((string) $name)] = $psrResponse->getHeaderLine((string) $name);
        }

        return new TransportResponse(
            $psrResponse->getStatusCode(),
            (string) $psrResponse->getBody(),
            $headers,
        );
    }
}
