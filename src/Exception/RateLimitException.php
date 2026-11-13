<?php

declare(strict_types=1);

namespace AyInbox\Exception;

/**
 * 429: too many requests. getRetryAfter() holds the wait in seconds.
 */
class RateLimitException extends ApiException
{
}
