<?php

declare(strict_types=1);

namespace AyInbox\Exception;

/**
 * 401: the credential is missing, wrong or no longer valid.
 */
class AuthenticationException extends ApiException
{
}
