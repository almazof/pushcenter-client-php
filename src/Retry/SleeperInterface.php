<?php

declare(strict_types=1);

namespace PushCenter\Client\Retry;

/** Time side effect behind an interface: tests use a recording fake. */
interface SleeperInterface
{
    public function sleepMs(int $milliseconds): void;
}
