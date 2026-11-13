<?php

declare(strict_types=1);

namespace AyInbox;

/**
 * One page of a list endpoint: the rows plus the cursor for the next page.
 *
 * @implements \IteratorAggregate<int, mixed>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /**
     * @param array<int, mixed>    $data
     * @param array<string, mixed> $raw  The whole decoded response, including fields such as `counts`.
     */
    public function __construct(
        public readonly array $data,
        public readonly ?string $nextCursor,
        public readonly bool $hasMore,
        public readonly ?int $total,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<mixed> $body
     */
    public static function fromResponse(array $body): self
    {
        $data = isset($body['data']) && is_array($body['data']) ? array_values($body['data']) : [];
        $pagination = isset($body['pagination']) && is_array($body['pagination']) ? $body['pagination'] : [];
        $cursor = $pagination['next_cursor'] ?? null;
        $total = $pagination['total'] ?? null;

        /** @var array<string, mixed> $raw */
        $raw = $body;

        return new self(
            $data,
            is_string($cursor) && $cursor !== '' ? $cursor : null,
            ($pagination['has_more'] ?? false) === true,
            is_int($total) ? $total : null,
            $raw,
        );
    }

    /**
     * @return \ArrayIterator<int, mixed>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function count(): int
    {
        return count($this->data);
    }
}
