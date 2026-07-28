<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Support;

/**
 * Locates the golden request/response fixtures of the gateway API contract.
 *
 * By default the fixtures vendored in `tests/fixtures/` are used, so the suite
 * is self-contained: `scripts/check.sh` passes on a fresh checkout with nothing
 * but PHP and composer. Their origin and update policy are documented in
 * `tests/fixtures/README.md`.
 *
 * Setting `PUSHCENTER_CONTRACT_DIR` to a checkout of the contract points the
 * same suite at the upstream fixtures instead — that is how drift between the
 * vendored copies and the contract is detected before a release.
 */
final class ContractPaths
{
    private function __construct()
    {
    }

    /** Directory holding the `valid/` and `invalid/` fixture sets. */
    public static function fixturesDir(): string
    {
        $env = getenv('PUSHCENTER_CONTRACT_DIR');
        if (is_string($env) && $env !== '') {
            $real = realpath($env);
            if ($real === false) {
                throw new \RuntimeException("PUSHCENTER_CONTRACT_DIR points to a missing directory: {$env}");
            }

            return $real . '/fixtures';
        }

        return dirname(__DIR__) . '/fixtures';
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
