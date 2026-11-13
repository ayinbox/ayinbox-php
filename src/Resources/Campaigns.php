<?php

declare(strict_types=1);

namespace AyInbox\Resources;

use AyInbox\Page;

final class Campaigns extends Resource
{
    /**
     * GET /campaigns: one page. Params: q, status, folder, cursor, limit (1 to 100 recommended).
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     */
    public function list(array $params = [], array $options = []): Page
    {
        return $this->client->requestPage('GET', '/campaigns', ['query' => $params] + $options);
    }

    /**
     * Every campaign matching $params, across all pages.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, mixed>
     */
    public function iterate(array $params = [], array $options = []): \Generator
    {
        return $this->client->paginate('/campaigns', $params, 'GET', $options);
    }

    /**
     * GET /campaigns/{id}.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function get(string $id, array $options = []): array
    {
        return self::object($this->client->request('GET', '/campaigns/' . self::segment($id), $options));
    }

    /**
     * POST /campaigns: creates a draft. `name` is required. Never sends mail.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options idempotency_key, headers, timeout.
     *
     * @return array<string, mixed>
     */
    public function create(array $params, array $options = []): array
    {
        return self::object($this->client->request('POST', '/campaigns', ['body' => $params] + $options));
    }

    /**
     * POST /campaigns/{id}/stop: pauses an active campaign. Sends nothing.
     *
     * @param array<string, mixed> $options idempotency_key, headers, timeout.
     *
     * @return array<string, mixed>
     */
    public function pause(string $id, array $options = []): array
    {
        return self::object($this->client->request('POST', '/campaigns/' . self::segment($id) . '/stop', $options));
    }
}
