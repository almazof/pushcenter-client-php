<?php

declare(strict_types=1);

namespace PushCenter\Client\Transport;

use PushCenter\Client\Exception\TransportException;

/**
 * Minimal HTTP port of the client. Two implementations ship with the
 * package (CurlTransport, Psr18Transport); tests plug fakes.
 */
interface TransportInterface
{
    /** @throws TransportException on any network-level failure */
    public function send(TransportRequest $request): TransportResponse;
}
