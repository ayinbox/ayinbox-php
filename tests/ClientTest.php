<?php

declare(strict_types=1);

namespace AyInbox\Tests;

use AyInbox\AyInbox;
use AyInbox\Exception\AyInboxException;
use GuzzleHttp\Psr7\Response;

final class ClientTest extends TestCase
{
    public function testSendsAuthUserAgentAndAccept(): void
    {
        $client = $this->client([self::json('me.json')]);
        $me = $client->me->get();

        self::assertSame('dana@example.com', $me['email']);
        $request = $this->request();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.test/v1/me', (string) $request->getUri());
        self::assertSame('Bearer ' . self::KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('ayinbox-php/' . AyInbox::VERSION, $request->getHeaderLine('User-Agent'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertFalse($request->hasHeader('Content-Type'));
        self::assertFalse($request->hasHeader('Idempotency-Key'));
    }

    public function testStripsTrailingSlashFromBaseUrl(): void
    {
        $client = $this->client([self::json('me.json')], 2, 'https://api.test/v1/');
        $client->me->get();

        self::assertSame('https://api.test/v1/me', (string) $this->request()->getUri());
    }

    public function testReadsKeyAndBaseUrlFromEnvironment(): void
    {
        putenv('AYINBOX_API_KEY=aybox_from_env');
        putenv('AYINBOX_BASE_URL=https://env.test/v1');
        try {
            $client = new AyInbox();
            $debug = print_r($client, true);
            self::assertStringContainsString('https://env.test/v1', $debug);
            self::assertStringNotContainsString('aybox_from_env', $debug);
        } finally {
            putenv('AYINBOX_API_KEY');
            putenv('AYINBOX_BASE_URL');
        }
    }

    public function testRefusesToStartWithoutAKey(): void
    {
        putenv('AYINBOX_API_KEY');
        $this->expectException(AyInboxException::class);
        new AyInbox();
    }

    public function testGenericRequestSendsQueryBodyAndHeaders(): void
    {
        $client = $this->client([new Response(200, ['Content-Type' => 'application/json'], '{"count":4}')]);
        $result = $client->request('POST', '/unibox/reply', [
            'query' => ['dry' => true, 'ids' => ['a', 'b'], 'skip' => null],
            'body' => ['to' => ['lee@example.com'], 'scheduled_at' => null],
            'idempotency_key' => 'key-123',
            'headers' => ['X-Request-Id' => 'my-req-1'],
        ]);

        self::assertSame(['count' => 4], $result);
        $request = $this->request();
        self::assertSame('dry=true&ids=a%2Cb', $request->getUri()->getQuery());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('key-123', $request->getHeaderLine('Idempotency-Key'));
        self::assertSame('my-req-1', $request->getHeaderLine('X-Request-Id'));
        self::assertSame('{"to":["lee@example.com"],"scheduled_at":null}', (string) $request->getBody());
    }

    public function testEmptyAndNonJsonBodies(): void
    {
        $client = $this->client([
            new Response(204),
            new Response(200, ['Content-Type' => 'text/plain'], 'ok'),
        ]);

        self::assertNull($client->request('DELETE', '/templates/x'));
        self::assertSame('ok', $client->request('GET', '/plain'));
    }
}
