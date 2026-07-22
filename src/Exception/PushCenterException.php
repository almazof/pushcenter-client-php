<?php

declare(strict_types=1);

namespace PushCenter\Client\Exception;

/**
 * Base of the client exception hierarchy. Catch this to handle any
 * PushCenter failure with a single catch block.
 */
class PushCenterException extends \RuntimeException
{
}
