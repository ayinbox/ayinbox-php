<?php

declare(strict_types=1);

namespace AyInbox\Tests;

use GuzzleHttp\Psr7\Response;

final class ResourcesTest extends TestCase
{
    private const CAMPAIGN = '11111111-1111-4111-8111-111111111111';
    private const CONTACT = '22222222-2222-4222-8222-111111111111';

    public function testCampaignGet(): void
    {
        $client = $this->client([self::json('campaign.json')]);
        $campaign = $client->campaigns->get(self::CAMPAIGN);

        self::assertSame(self::CAMPAIGN, $campaign['id']);
        self::assertSame('/v1/campaigns/' . self::CAMPAIGN, $this->request()->getUri()->getPath());
    }

    public function testCampaignCreateSendsBodyAndIdempotencyKey(): void
    {
        $client = $this->client([self::json('campaign.json')]);
        $client->campaigns->create(['name' => 'Q4 outreach', 'daily_limit' => 30], ['idempotency_key' => 'q4-create']);

        $request = $this->request();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/campaigns', $request->getUri()->getPath());
        self::assertSame('q4-create', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame(['name' => 'Q4 outreach', 'daily_limit' => 30], $this->sentBody());
    }

    public function testCampaignPause(): void
    {
        $client = $this->client([self::json('campaign_stop.json')]);
        $result = $client->campaigns->pause(self::CAMPAIGN, ['idempotency_key' => 'pause-1']);

        self::assertSame('stopped', $result['status']);
        self::assertSame('/v1/campaigns/' . self::CAMPAIGN . '/stop', $this->request()->getUri()->getPath());
        self::assertSame('', (string) $this->request()->getBody());
    }

    public function testContactsListSendsEmptyObject(): void
    {
        $client = $this->client([self::json('contacts_list.json')]);
        $page = $client->contacts->list([], ['limit' => 25]);

        self::assertCount(2, $page);
        self::assertSame('POST', $this->request()->getMethod());
        self::assertSame('/v1/contacts/search', $this->request()->getUri()->getPath());
        self::assertSame('limit=25', $this->request()->getUri()->getQuery());
        self::assertSame('{}', (string) $this->request()->getBody());
        self::assertArrayHasKey('pagination', $page->raw);
    }

    public function testContactGet(): void
    {
        $client = $this->client([self::json('contact.json')]);
        $contact = $client->contacts->get(self::CONTACT);

        self::assertSame(self::CONTACT, $contact['id']);
        self::assertSame('/v1/contacts/' . self::CONTACT, $this->request()->getUri()->getPath());
    }

    public function testContactCreateReturnsAList(): void
    {
        $client = $this->client([self::json('contacts_create.json')]);
        $contacts = $client->contacts->create(
            ['email' => 'lee@example.com', 'first_name' => 'Lee', 'campaigns' => [self::CAMPAIGN]],
            ['idempotency_key' => 'import-lee'],
        );

        self::assertSame('lee@example.com', $contacts[0]['email']);
        self::assertSame('import-lee', $this->request()->getHeaderLine('Idempotency-Key'));
        self::assertSame('lee@example.com', $this->sentBody()['email']);
    }

    public function testContactUpdateCanSendExplicitNull(): void
    {
        $created = json_decode(self::fixture('contacts_create.json'), true);
        self::assertIsArray($created);
        $client = $this->client([new Response(200, [], (string) json_encode($created[0]))]);
        $contact = $client->contacts->update(self::CONTACT, ['company' => 'New Co', 'phone' => null]);

        self::assertSame(self::CONTACT, $contact['id']);
        self::assertSame('PATCH', $this->request()->getMethod());
        self::assertSame('{"company":"New Co","phone":null}', (string) $this->request()->getBody());
    }

    public function testContactDelete(): void
    {
        $client = $this->client([new Response(204)]);
        $client->contacts->delete(self::CONTACT, ['idempotency_key' => 'del-1']);

        self::assertSame('DELETE', $this->request()->getMethod());
        self::assertSame('/v1/contacts/' . self::CONTACT, $this->request()->getUri()->getPath());
    }

    public function testMailboxesList(): void
    {
        $client = $this->client([self::json('mailboxes_list.json')]);
        $page = $client->mailboxes->list(['q' => 'sales']);

        self::assertCount(2, $page);
        self::assertFalse($page->hasMore);
        self::assertNull($page->nextCursor);
        self::assertSame('/v1/emails', $this->request()->getUri()->getPath());
    }

    public function testUniboxListThreads(): void
    {
        $client = $this->client([self::json('unibox_threads.json')]);
        $page = $client->unibox->listThreads(['folder' => 'inbox', 'unseen' => true]);

        self::assertCount(1, $page);
        self::assertTrue($page->hasMore);
        self::assertNull($page->total);
        self::assertSame('folder=inbox&unseen=true', $this->request()->getUri()->getQuery());
    }

    public function testUniboxGetThread(): void
    {
        $client = $this->client([self::json('unibox_thread.json')]);
        $page = $client->unibox->getThread('thread-example-001', ['limit' => 100]);

        self::assertCount(2, $page);
        self::assertSame('/v1/unibox/thread', $this->request()->getUri()->getPath());
        self::assertSame('thread_id=thread-example-001&limit=100', $this->request()->getUri()->getQuery());
    }

    public function testUnknownFieldsAreKept(): void
    {
        $client = $this->client([new Response(200, [], '{"user_id":"u","email":"a@b.c","brand_new_field":{"x":1}}')]);
        $me = $client->me->get();

        self::assertSame(['x' => 1], $me['brand_new_field']);
    }
}
