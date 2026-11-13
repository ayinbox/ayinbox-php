<?php

declare(strict_types=1);

namespace AyInbox\Resources;

use AyInbox\Page;

final class Unibox extends Resource
{
    /**
     * GET /unibox: one row per conversation. Params: cursor, limit, folder, unseen, campaign_id, ...
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     */
    public function listThreads(array $params = [], array $options = []): Page
    {
        return $this->client->requestPage('GET', '/unibox', ['query' => $params] + $options);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, mixed>
     */
    public function iterateThreads(array $params = [], array $options = []): \Generator
    {
        return $this->client->paginate('/unibox', $params, 'GET', $options);
    }

    /**
     * GET /unibox/thread: the messages of one thread (previews). Params: email_id, limit, cursor.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     */
    public function getThread(string $threadId, array $params = [], array $options = []): Page
    {
        return $this->client->requestPage('GET', '/unibox/thread', [
            'query' => ['thread_id' => $threadId] + $params,
        ] + $options);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, mixed>
     */
    public function iterateThread(string $threadId, array $params = [], array $options = []): \Generator
    {
        return $this->client->paginate('/unibox/thread', ['thread_id' => $threadId] + $params, 'GET', $options);
    }
}
