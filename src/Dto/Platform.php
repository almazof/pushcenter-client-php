<?php

declare(strict_types=1);

namespace PushCenter\Client\Dto;

enum Platform: string
{
    case Ios = 'ios';
    case Android = 'android';
}
