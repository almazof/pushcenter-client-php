<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

final class UnbindUserResult
{
    public function __construct(public readonly bool $unbound)
    {
    }
}
