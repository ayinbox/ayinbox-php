<?php

declare(strict_types=1);

namespace AyInbox\Exception;

/**
 * The request did not get an HTTP answer: DNS, TLS, refused connection or timeout.
 */
class ConnectionException extends AyInboxException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
