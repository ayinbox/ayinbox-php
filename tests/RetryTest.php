<?php

declare(strict_types=1);

namespace AyInbox\Tests;

use AyInbox\Exception\ConflictException;
use AyInbox\Exception\ConnectionException;
use AyInbox\Exception\InternalServerException;
use AyInbox\Exception\RateLimitException;
use AyInbox\Exception\ServiceUnavailableException;
use AyInbox\Exception\TimeoutException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;

final class RetryTest extends TestCase
{
    public function testRetriesA503ThenSucceeds(): void
    {
        $client = $this->client([self::error('503_service_unavailable.json'), self::json('me.json')]);
        $me = $client->me->get();

        self::assertSame('dana@example.com', $me['email']);
        self::assertCount(2, $this->history);
        self::assertSame([5.0], $this->sleeps);
    }

    public function testRetriesA429WithRetryAfterThenSucceeds(): void
    {
        $client = $this->client([self::error('429_api_key_rate_limited.json'), self::json('campaigns_list.json')]);
        $page = $client->campaigns->list();

        self::assertCount(2, $page->data);
        self::assertSame([12.0], $this->sleeps);
    }

    public function testBacksOffWithJitterWhenNoRetryAfter(): void
    {
        $client = $this->client([
            new \GuzzleHttp\Psr7\Response(502),
            new \GuzzleHttp\Psr7\Response(504),
            self::json('me.json'),
        ]);
        $client->me->get();

        self::assertCount(3, $this->history);
        self::assertCount(2, $this->sleeps);
        self::assertLessThanOrEqual(0.5, $this->sleeps[0]);
        self::assertLessThanOrEqual(1.0, $this->sleeps[1]);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $client = $this->client([
            self::error('503_service_unavailable.json'),
            self::error('503_service_unavailable.json'),
            self::error('503_service_unavailable.json'),
        ]);

        $this->expectException(ServiceUnavailableException::class);
        try {
            $client->me->get();
        } finally {
            self::assertCount(3, $this->history);
        }
    }

    public function testZeroDisablesRetries(): void
    {
        $client = $this->client([self::error('503_service_unavailable.json'), self::json('me.json')], 0);

        $this->expectException(ServiceUnavailableException::class);
        $client->me->get();
    }

    public function testDoesNotRetryAKeylessPostOn503(): void
    {
        $client = $this->client([self::error('503_service_unavailable.json'), self::json('campaign.json')]);

        try {
            $client->campaigns->create(['name' => 'Q4 outreach']);
            self::fail('expected an exception');
        } catch (ServiceUnavailableException) {
            self::assertCount(1, $this->history);
            self::assertSame([], $this->sleeps);
        }
    }

    public function testDoesNotRetryAKeylessPostOnConnectionError(): void
    {
        $client = $this->client([
            new ConnectException('Connection refused', new Request('POST', 'https://api.test/v1/contacts')),
            self::json('contacts_create.json'),
        ]);

        $this->expectException(ConnectionException::class);
        try {
            $client->contacts->create(['email' => 'lee@example.com']);
        } finally {
            self::assertCount(1, $this->history);
        }
    }

    public function testRetriesAKeylessPostOn429Only(): void
    {
        $client = $this->client([self::error('429_category_rate_limited.json'), self::json('campaign.json')]);
        $campaign = $client->campaigns->create(['name' => 'Q4 outreach']);

        self::assertSame('11111111-1111-4111-8111-111111111111', $campaign['id']);
        self::assertCount(2, $this->history);
    }

    public function testRetriesAPostWithIdempotencyKeyReusingTheKey(): void
    {
        $client = $this->client([self::error('503_service_unavailable.json'), self::json('campaign.json')]);
        $client->campaigns->create(['name' => 'Q4 outreach'], ['idempotency_key' => 'create-q4']);

        self::assertCount(2, $this->history);
        self::assertSame('create-q4', $this->request(0)->getHeaderLine('Idempotency-Key'));
        self::assertSame('create-q4', $this->request(1)->getHeaderLine('Idempotency-Key'));
        self::assertSame((string) $this->request(0)->getBody(), (string) $this->request(1)->getBody());
    }

    public function testRetriesContactSearchWithoutAKey(): void
    {
        $client = $this->client([self::error('503_service_unavailable.json'), self::json('contacts_list.json')]);
        $page = $client->contacts->list();

        self::assertCount(2, $page->data);
        self::assertCount(2, $this->history);
    }

    public function testRetriesAGetOnConnectionErrorAndMapsTimeout(): void
    {
        $request = new Request('GET', 'https://api.test/v1/me');
        $client = $this->client([
            new ConnectException('Connection refused', $request),
            new ConnectException('cURL error 28: Operation timed out', $request, null, ['errno' => 28]),
            new ConnectException('cURL error 28: Operation timed out', $request, null, ['errno' => 28]),
        ]);

        $this->expectException(TimeoutException::class);
        try {
            $client->me->get();
        } finally {
            self::assertCount(3, $this->history);
        }
    }

    public function testNeverRetries500Or409(): void
    {
        $client = $this->client([self::error('500_internal_error.json'), self::error('409_idempotency_outcome_unknown.json')]);

        try {
            $client->me->get();
            self::fail('expected an exception');
        } catch (InternalServerException) {
            self::assertCount(1, $this->history);
        }
        try {
            $client->campaigns->create(['name' => 'x'], ['idempotency_key' => 'k']);
            self::fail('expected an exception');
        } catch (ConflictException $e) {
            self::assertSame('idempotency_outcome_unknown', $e->getErrorCode());
            self::assertCount(2, $this->history);
        }
    }

    public function testCapsRetryAfter(): void
    {
        $client = $this->client([
            new \GuzzleHttp\Psr7\Response(429, ['Retry-After' => '900'], '{"error":"rate_limit_exceeded","message":"x"}'),
            self::json('me.json'),
        ]);
        $client->me->get();

        self::assertSame([120.0], $this->sleeps);
    }

    public function testRateLimitSurfacesAfterRetries(): void
    {
        $client = $this->client([self::error('429_api_key_rate_limited.json')], 0);

        $this->expectException(RateLimitException::class);
        $client->me->get();
    }
}
