<?php

declare(strict_types=1);

namespace PushCenter\Client\Tests\Support;

use PushCenter\Client\Retry\SleeperInterface;

final class RecordingSleeper implements SleeperInterface
{
    /** @var list<int> */
    public array $sleptMs = [];

    public function sleepMs(int $milliseconds): void
    {
        $this->sleptMs[] = $milliseconds;
    }
}
