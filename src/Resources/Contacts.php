<?php

declare(strict_types=1);

namespace AyInbox\Resources;

use AyInbox\Page;

final class Contacts extends Resource
{
    /**
     * POST /contacts/search: one page. It only reads, so it is retried like a GET.
     *
     * @param array<string, mixed> $filters Search body (query, campaign_ids, sort_by, ...). Empty matches everything.
     * @param array<string, mixed> $params  Query: cursor, limit, category.
     * @param array<string, mixed> $options
     */
    public function list(array $filters = [], array $params = [], array $options = []): Page
    {
        return $this->client->requestPage('POST', '/contacts/search', [
            'query' => $params,
            'body' => $filters === [] ? new \stdClass() : $filters,
            'retry_safe' => true,
        ] + $options);
    }

    /**
     * Every contact matching $filters, across all pages. The same body is sent on every page.
     *
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, mixed>
     */
    public function iterate(array $filters = [], array $params = [], array $options = []): \Generator
    {
        return $this->client->paginate('/contacts/search', $params, 'POST', [
            'body' => $filters === [] ? new \stdClass() : $filters,
            'retry_safe' => true,
        ] + $options);
    }

    /**
     * GET /contacts/{id}.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function get(string $id, array $options = []): array
    {
        return self::object($this->client->request('GET', '/contacts/' . self::segment($id), $options));
    }

    /**
     * POST /contacts: upserts one contact or a list of them. Always returns a list.
     * Adding a contact to an active campaign can send mail.
     *
     * @param array<mixed>         $contacts One contact (associative array) or a list of contacts.
     * @param array<string, mixed> $options  idempotency_key, headers, timeout.
     *
     * @return array<int, mixed>
     */
    public function create(array $contacts, array $options = []): array
    {
        $result = $this->client->request('POST', '/contacts', ['body' => $contacts] + $options);

        return is_array($result) ? array_values($result) : [];
    }

    /**
     * PATCH /contacts/{id}: only the fields sent change.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options idempotency_key, headers, timeout.
     *
     * @return array<string, mixed>
     */
    public function update(string $id, array $params, array $options = []): array
    {
        return self::object($this->client->request('PATCH', '/contacts/' . self::segment($id), [
            'body' => $params === [] ? new \stdClass() : $params,
        ] + $options));
    }

    /**
     * DELETE /contacts/{id}.
     *
     * @param array<string, mixed> $options idempotency_key, headers, timeout.
     */
    public function delete(string $id, array $options = []): void
    {
        $this->client->request('DELETE', '/contacts/' . self::segment($id), $options);
    }
}
