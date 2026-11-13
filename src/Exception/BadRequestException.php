<?php

declare(strict_types=1);

namespace AyInbox\Exception;

/**
 * 400: a malformed request, for example a missing field or a bad cursor.
 */
class BadRequestException extends ValidationException
{
}
