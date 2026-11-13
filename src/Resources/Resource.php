<?php

declare(strict_types=1);

namespace AyInbox\Resources;

use AyInbox\AyInbox;

/**
 * Shared plumbing for resource groups.
 */
abstract class Resource
{
    public function __construct(protected readonly AyInbox $client)
    {
    }

    protected static function segment(string $id): string
    {
        return rawurlencode($id);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function object(mixed $value): array
    {
        /** @var array<string, mixed> */
        return is_array($value) ? $value : [];
    }
}
