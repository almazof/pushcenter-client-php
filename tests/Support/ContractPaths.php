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
 * same suite at the upstream fixtures instead (`scripts/check.sh
 * --contract-drift`) — that is how drift between the vendored copies and the
 * contract is detected before a release. What each mode does and does NOT
 * guarantee is spelled out in `tests/fixtures/README.md`: the default run only
 * proves the vendored copies are honoured, the drift run proves they still
 * match upstream.
 */
final class ContractPaths
{
    private function __construct()
    {
    }

    /**
     * Directory holding the `valid/` and `invalid/` fixture sets the suite runs
     * against: the upstream contract when PUSHCENTER_CONTRACT_DIR is set, the
     * vendored copies otherwise.
     */
    public static function fixturesDir(): string
    {
        return self::upstreamFixturesDir() ?? self::vendoredFixturesDir();
    }

    /** Directory of the fixture copies vendored in this repository. */
    public static function vendoredFixturesDir(): string
    {
        return dirname(__DIR__) . '/fixtures';
    }

    /**
     * Fixture directory of the contract checkout PUSHCENTER_CONTRACT_DIR points
     * at, or null when the variable is unset — drift checks skip in that case.
     */
    public static function upstreamFixturesDir(): ?string
    {
        $env = getenv('PUSHCENTER_CONTRACT_DIR');
        if (!is_string($env) || $env === '') {
            return null;
        }

        $real = realpath($env);
        if ($real === false) {
            throw new \RuntimeException("PUSHCENTER_CONTRACT_DIR points to a missing directory: {$env}");
        }

        return $real . '/fixtures';
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
