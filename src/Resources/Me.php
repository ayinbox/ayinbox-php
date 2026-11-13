<?php

declare(strict_types=1);

namespace AyInbox\Resources;

final class Me extends Resource
{
    /**
     * GET /me: the identity behind the credential. Needs no scope.
     *
     * @param array<string, mixed> $options Per-request options (headers, timeout).
     *
     * @return array<string, mixed>
     */
    public function get(array $options = []): array
    {
        return self::object($this->client->request('GET', '/me', $options));
    }
}
