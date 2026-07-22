<?php

declare(strict_types=1);

namespace PushCenter\Client\Retry;

final class UsleepSleeper implements SleeperInterface
{
    public function sleepMs(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
