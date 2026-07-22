<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PushCenter\Client\ClientConfig;

final class ClientConfigTest extends TestCase
{
    public function testTrailingSlashIsStripped(): void
    {
        $config = new ClientConfig('http://gateway.test/', 'key');
        self::assertSame('http://gateway.test', $config->baseUrl);
    }

    public function testRejectsNonHttpBaseUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClientConfig('ftp://gateway.test', 'key');
    }

    public function testRejectsEmptyApiKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClientConfig('http://gateway.test', '');
    }

    /** @return iterable<string, array{string}> */
    public static function crlfValues(): iterable
    {
        yield 'CR' => ["key\rinjected"];
        yield 'LF' => ["key\ninjected"];
        yield 'CRLF' => ["key\r\nX-Evil: 1"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('crlfValues')]
    public function testRejectsCrLfInApiKey(string $apiKey): void
    {
        // The key is sent as a header value: CR/LF = header injection.
        $this->expectException(\InvalidArgumentException::class);
        new ClientConfig('http://gateway.test', $apiKey);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('crlfValues')]
    public function testRejectsCrLfInBaseUrl(string $suffix): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ClientConfig('http://gateway.test/' . $suffix, 'key');
    }
}
