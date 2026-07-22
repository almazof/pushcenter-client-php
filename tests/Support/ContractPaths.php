<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Support;

/** Locates the contract repo checked out next door (golden fixtures). */
final class ContractPaths
{
    private function __construct()
    {
    }

    public static function contractDir(): string
    {
        $env = getenv('PUSHCENTER_CONTRACT_DIR');
        $dir = is_string($env) && $env !== '' ? $env : dirname(__DIR__, 3) . '/contract';
        $real = realpath($dir);
        if ($real === false) {
            throw new \RuntimeException("Contract repo not found at {$dir}");
        }

        return $real;
    }

    public static function fixturesDir(): string
    {
        return self::contractDir() . '/fixtures';
    }

    /** @return array<string, mixed> */
    public static function fixture(string $relative): array
    {
        $raw = file_get_contents(self::fixturesDir() . '/' . $relative);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read fixture {$relative}");
        }
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Fixture {$relative} is not a JSON object");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
