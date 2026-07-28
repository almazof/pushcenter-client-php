<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PushCenter\Client\Tests\Support\ContractPaths;

/**
 * Guards the vendored golden fixtures against drift from the contract they were
 * copied from.
 *
 * The other fixture suites READ fixtures: they prove that whatever set they are
 * pointed at is honoured by the client. They cannot prove the vendored set is
 * still the upstream set — a fixture added upstream is simply absent from a
 * glob over the local copy, and the valid-fixture tests name their files one by
 * one. This test compares the two directory listings as SETS, in both
 * directions, which is the only check that notices an addition upstream.
 *
 * It needs both sides, so it runs only when PUSHCENTER_CONTRACT_DIR points at a
 * contract checkout — `scripts/check.sh --contract-drift`. Without it the test
 * skips loudly rather than passing vacuously.
 */
final class FixtureParityTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function fixtureSets(): iterable
    {
        yield 'valid' => ['valid'];
        yield 'invalid' => ['invalid'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fixtureSets')]
    public function testVendoredSetMatchesUpstream(string $set): void
    {
        $upstreamDir = ContractPaths::upstreamFixturesDir();
        if ($upstreamDir === null) {
            self::markTestSkipped(
                'Fixture drift is only checkable against the contract: run '
                . '`scripts/check.sh --contract-drift=/path/to/contract` (or set '
                . 'PUSHCENTER_CONTRACT_DIR). Without it the vendored copies in '
                . 'tests/fixtures/ are taken at face value.'
            );
        }

        $vendored = self::fixtureNames(ContractPaths::vendoredFixturesDir() . '/' . $set);
        $upstream = self::fixtureNames($upstreamDir . '/' . $set);

        $missingHere = array_values(array_diff($upstream, $vendored));
        $extraHere = array_values(array_diff($vendored, $upstream));

        self::assertSame(
            [],
            $missingHere,
            "Fixtures added to the contract but not vendored into tests/fixtures/{$set}/: "
            . implode(', ', $missingHere)
            . '. Copy them over and extend the suites (see tests/fixtures/README.md).'
        );
        self::assertSame(
            [],
            $extraHere,
            "Fixtures present in tests/fixtures/{$set}/ but gone from the contract: "
            . implode(', ', $extraHere)
            . '. The contract is the source of truth — drop or rename the local copies.'
        );
    }

    /**
     * Every fixture of one set, sorted, as bare file names.
     *
     * @return list<string>
     */
    private static function fixtureNames(string $dir): array
    {
        $paths = glob($dir . '/*.json');
        self::assertNotFalse($paths, "Cannot list fixture directory {$dir}");
        self::assertNotEmpty($paths, "No fixtures found in {$dir} — is the directory intact?");

        $names = array_map('basename', $paths);
        sort($names);

        return $names;
    }
}
