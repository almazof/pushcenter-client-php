<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PushCenter\Client\IdempotencyKey;

final class IdempotencyKeyTest extends TestCase
{
    public function testDeterministicIsStable(): void
    {
        $a = IdempotencyKey::deterministic('evt-1', 'order_created', ['b' => 2, 'a' => 1], 'user-7');
        $b = IdempotencyKey::deterministic('evt-1', 'order_created', ['b' => 2, 'a' => 1], 'user-7');

        self::assertSame($a, $b);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
    }

    public function testDataKeyOrderDoesNotMatter(): void
    {
        self::assertSame(
            IdempotencyKey::deterministic('e', 't_x', ['a' => 1, 'b' => ['y' => 2, 'x' => 1]]),
            IdempotencyKey::deterministic('e', 't_x', ['b' => ['x' => 1, 'y' => 2], 'a' => 1]),
        );
    }

    public function testDifferentInputsProduceDifferentKeys(): void
    {
        $base = IdempotencyKey::deterministic('e', 't_x', ['a' => 1], 's1');
        self::assertNotSame($base, IdempotencyKey::deterministic('e2', 't_x', ['a' => 1], 's1'));
        self::assertNotSame($base, IdempotencyKey::deterministic('e', 't_y', ['a' => 1], 's1'));
        self::assertNotSame($base, IdempotencyKey::deterministic('e', 't_x', ['a' => 2], 's1'));
        self::assertNotSame($base, IdempotencyKey::deterministic('e', 't_x', ['a' => 1], 's2'));
    }

    /**
     * Pins the documented formula byte for byte, so a sender that recomputes
     * the key on its own side can rely on it:
     * sha256(implode('|', [eventId, eventType, ...scope, json(recursively ksorted data)]))
     * with JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES.
     */
    public function testMatchesTheDocumentedReferenceFormula(): void
    {
        $data = ['z' => ['b' => 1, 'a' => 'я'], 'a' => 'x/y'];
        $normalized = $data;
        ksort($normalized);
        ksort($normalized['z']);
        $expected = hash('sha256', implode('|', [
            'evt-42',
            'order_approved',
            'user-9',
            (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));

        self::assertSame(
            $expected,
            IdempotencyKey::deterministic('evt-42', 'order_approved', $data, 'user-9'),
        );
    }

    public function testRandomIsContractValidAndUnique(): void
    {
        $a = IdempotencyKey::random();
        $b = IdempotencyKey::random();

        self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]{16,128}$/', $a);
        self::assertNotSame($a, $b);
    }
}
