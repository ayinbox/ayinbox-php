<?php

declare(strict_types=1);

namespace AyInbox\Exception;

/**
 * 500, 501, 502, 504 or another 5xx.
 */
class InternalServerException extends ApiException
{
}
