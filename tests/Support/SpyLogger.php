<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Support;

use Psr\Log\AbstractLogger;

final class SpyLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => is_string($level) ? $level : gettype($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    public function hasErrorContaining(string $needle): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === 'error' && str_contains($record['message'], $needle)) {
                return true;
            }
        }

        return false;
    }
}
