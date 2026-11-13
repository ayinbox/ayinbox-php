<?php

declare(strict_types=1);

namespace AyInbox\Exception;

/**
 * The request timed out before the API answered.
 */
class TimeoutException extends ConnectionException
{
}
