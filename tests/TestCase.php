<?php

declare(strict_types=1);

namespace AyInbox\Tests;

use AyInbox\AyInbox;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const KEY = 'aybox_test_0000000000000000000000000000000000000';

    /** @var array<int, array{request: RequestInterface}> */
    protected array $history = [];

    /** @var array<int, float> */
    protected array $sleeps = [];

    protected MockHandler $mock;

    /**
     * @param array<int, mixed> $queue Responses or exceptions, in order.
     */
    protected function client(array $queue, int $maxRetries = 2, string $baseUrl = 'https://api.test/v1'): AyInbox
    {
        $this->history = [];
        $this->sleeps = [];
        $this->mock = new MockHandler($queue);
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        return new AyInbox(
            apiKey: self::KEY,
            baseUrl: $baseUrl,
            maxRetries: $maxRetries,
            httpClient: new Client(['handler' => $stack]),
            sleeper: function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
    }

    protected static function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/' . $name);
        self::assertIsString($contents);

        return $contents;
    }

    /**
     * @param array<string, string> $headers
     */
    protected static function json(string $fixture, int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers + [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Request-Id' => 'req-fixture-0001',
            'API-Version' => 'v1',
        ], self::fixture($fixture));
    }

    /**
     * Builds an error response with the status and headers fixtures/index.json gives it.
     */
    protected static function error(string $name): Response
    {
        $status = (int) substr($name, 0, 3);
        $headers = ['Content-Type' => 'application/json; charset=utf-8'];
        if ($name !== '429_category_rate_limited.json') {
            $headers['X-Request-Id'] = 'req-fixture-0001';
        }
        if (str_starts_with($name, '429')) {
            $headers['Retry-After'] = '12';
        }
        if ($name === '503_service_unavailable.json') {
            $headers['Retry-After'] = '5';
        }

        return new Response($status, $headers, self::fixture('errors/' . $name));
    }

    protected function request(int $index = 0): RequestInterface
    {
        self::assertArrayHasKey($index, $this->history);

        return $this->history[$index]['request'];
    }

    /**
     * @return mixed
     */
    protected function sentBody(int $index = 0): mixed
    {
        return json_decode((string) $this->request($index)->getBody(), true);
    }
}
