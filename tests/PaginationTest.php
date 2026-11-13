<?php

declare(strict_types=1);

namespace AyInbox\Tests;

final class PaginationTest extends TestCase
{
    public function testListReturnsOnePageWithCursor(): void
    {
        $client = $this->client([self::json('campaigns_list.json')]);
        $page = $client->campaigns->list(['status' => 'draft', 'limit' => 2]);

        self::assertCount(2, $page);
        self::assertTrue($page->hasMore);
        self::assertSame('c1_ZmFrZS1jdXJzb3ItcGFnZS0y', $page->nextCursor);
        self::assertSame(3, $page->total);
        self::assertSame('status=draft&limit=2', $this->request()->getUri()->getQuery());
    }

    public function testIterateWalksTwoPages(): void
    {
        $client = $this->client([self::json('campaigns_list.json'), self::json('campaigns_list_page2.json')]);
        $names = [];
        foreach ($client->campaigns->iterate(['limit' => 2]) as $campaign) {
            self::assertIsArray($campaign);
            $names[] = $campaign['id'];
        }

        self::assertCount(3, $names);
        self::assertCount(2, $this->history);
        self::assertSame('limit=2', $this->request(0)->getUri()->getQuery());
        self::assertSame('limit=2&cursor=c1_ZmFrZS1jdXJzb3ItcGFnZS0y', $this->request(1)->getUri()->getQuery());
    }

    public function testGenericPaginateWaitsOutA429MidWalk(): void
    {
        $client = $this->client([
            self::json('campaigns_list.json'),
            self::error('429_api_key_rate_limited.json'),
            self::json('campaigns_list_page2.json'),
        ]);
        $items = iterator_to_array($client->paginate('/campaigns'), false);

        self::assertCount(3, $items);
        self::assertSame([12.0], $this->sleeps);
    }

    public function testContactIterateResendsTheSameBody(): void
    {
        $first = json_decode(self::fixture('contacts_list.json'), true);
        self::assertIsArray($first);
        $first['pagination'] = ['next_cursor' => 'c1_next', 'has_more' => true, 'total' => 4];
        $client = $this->client([
            new \GuzzleHttp\Psr7\Response(200, [], (string) json_encode($first)),
            self::json('contacts_list.json'),
        ]);
        $items = iterator_to_array($client->contacts->iterate(['sort_by' => 'email', 'reverse' => true]), false);

        self::assertCount(4, $items);
        self::assertSame('POST', $this->request(1)->getMethod());
        self::assertSame('cursor=c1_next', $this->request(1)->getUri()->getQuery());
        self::assertSame((string) $this->request(0)->getBody(), (string) $this->request(1)->getBody());
        self::assertSame(['sort_by' => 'email', 'reverse' => true], $this->sentBody(1));
    }
}
