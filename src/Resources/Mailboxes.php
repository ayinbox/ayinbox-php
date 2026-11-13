<?php

declare(strict_types=1);

namespace AyInbox\Resources;

use AyInbox\Page;

final class Mailboxes extends Resource
{
    /**
     * GET /emails: one page of connected mailboxes. Params: q, tag, cursor, limit.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     */
    public function list(array $params = [], array $options = []): Page
    {
        return $this->client->requestPage('GET', '/emails', ['query' => $params] + $options);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, mixed>
     */
    public function iterate(array $params = [], array $options = []): \Generator
    {
        return $this->client->paginate('/emails', $params, 'GET', $options);
    }
}
