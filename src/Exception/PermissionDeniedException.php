<?php

declare(strict_types=1);

namespace AyInbox\Exception;

/**
 * 403: the credential lacks the scope or access for this call.
 */
class PermissionDeniedException extends ApiException
{
}
