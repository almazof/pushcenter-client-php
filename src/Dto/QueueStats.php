<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

final class QueueStats
{
    public function __construct(
        public readonly int $depth,
        public readonly int $delayed,
        public readonly int $processing,
        public readonly int $dlq,
    ) {
    }
}
