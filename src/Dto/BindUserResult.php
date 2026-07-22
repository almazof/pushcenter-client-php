<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

final class BindUserResult
{
    public function __construct(public readonly bool $bound)
    {
    }
}
