<?php

declare(strict_types=1);

namespace AyInbox;

use AyInbox\Exception\ApiException;
use AyInbox\Exception\AyInboxException;
use AyInbox\Exception\ConnectionException;
use AyInbox\Exception\TimeoutException;
use AyInbox\Resources\Campaigns;
use AyInbox\Resources\Contacts;
use AyInbox\Resources\Mailboxes;
use AyInbox\Resources\Me;
use AyInbox\Resources\Unibox;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

/**
 * Client for the AY Inbox REST API.
 */
final class AyInbox
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.ayinbox.com/v1';

    private const RETRY_AFTER_CAP = 120.0;
    private const BACKOFF_BASE = 0.5;
    private const BACKOFF_CAP = 8.0;

    public readonly Me $me;
    public readonly Campaigns $campaigns;
    public readonly Contacts $contacts;
    public readonly Mailboxes $mailboxes;
    public readonly Unibox $unibox;

    private readonly string $apiKey;
    private readonly string $baseUrl;
    private readonly float $timeout;
    private readonly int $maxRetries;
    private readonly ClientInterface $http;
    /** @var callable(float):void */
    private $sleeper;

    /**
     * @param string|null          $apiKey     Falls back to AYINBOX_API_KEY.
     * @param string|null          $baseUrl    Falls back to AYINBOX_BASE_URL, then the hosted API.
     * @param float                $timeout    Seconds per attempt.
     * @param int                  $maxRetries Automatic retries; 0 disables them.
     * @param ClientInterface|null $httpClient Any Guzzle 7 client.
     * @param (callable(float):void)|null $sleeper Waits between retries; replace it in tests.
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        float $timeout = 30.0,
        int $maxRetries = 2,
        ?ClientInterface $httpClient = null,
        ?callable $sleeper = null,
    ) {
        $apiKey ??= self::env('AYINBOX_API_KEY');
        if ($apiKey === null || $apiKey === '') {
            throw new AyInboxException('No API key: pass apiKey or set AYINBOX_API_KEY.');
        }
        $baseUrl ??= self::env('AYINBOX_BASE_URL') ?? self::DEFAULT_BASE_URL;
        if ($baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->maxRetries = max(0, $maxRetries);
        $this->http = $httpClient ?? new GuzzleClient();
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };

        $this->me = new Me($this);
        $this->campaigns = new Campaigns($this);
        $this->contacts = new Contacts($this);
        $this->mailboxes = new Mailboxes($this);
        $this->unibox = new Unibox($this);
    }

    /**
     * Sends any request to the API with the client's auth, retries and error mapping.
     *
     * Options: `query` (array), `body` (anything JSON-encodable; `null` sends no body),
     * `idempotency_key` (string), `headers` (array<string,string>), `timeout` (float seconds),
     * `retry_safe` (bool, marks a POST that only reads so it may be retried without a key).
     *
     * @param array<string, mixed> $options
     *
     * @return mixed Decoded JSON, a string for a non-JSON body, or null for an empty body.
     */
    public function request(string $method, string $path, array $options = []): mixed
    {
        return $this->send($method, $path, $options)['body'];
    }

    /**
     * Fetches one page from a paginated endpoint.
     *
     * @param array<string, mixed> $options Same as request().
     */
    public function requestPage(string $method, string $path, array $options = []): Page
    {
        $body = $this->request($method, $path, $options);

        return Page::fromResponse(is_array($body) ? $body : []);
    }

    /**
     * Walks every page of a paginated endpoint and yields each item.
     *
     * @param array<string, mixed> $query
     * @param array<string, mixed> $options Same as request(); `query` is replaced per page.
     *
     * @return \Generator<int, mixed>
     */
    public function paginate(string $path, array $query = [], string $method = 'GET', array $options = []): \Generator
    {
        $cursor = null;
        do {
            $pageQuery = $query;
            if ($cursor !== null) {
                $pageQuery['cursor'] = $cursor;
            }
            $page = $this->requestPage($method, $path, ['query' => $pageQuery] + $options);
            foreach ($page->data as $item) {
                yield $item;
            }
            $cursor = $page->hasMore ? $page->nextCursor : null;
        } while ($cursor !== null && $cursor !== '');
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{status: int, body: mixed, headers: array<string, array<int, string>>}
     */
    private function send(string $method, string $path, array $options): array
    {
        $method = strtoupper($method);
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $query = isset($options['query']) && is_array($options['query']) ? $options['query'] : [];
        $qs = self::buildQuery($query);
        if ($qs !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . $qs;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => 'ayinbox-php/' . self::VERSION,
            'Accept' => 'application/json',
        ];
        $payload = null;
        if (array_key_exists('body', $options) && $options['body'] !== null) {
            $payload = json_encode(
                $options['body'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
            $headers['Content-Type'] = 'application/json';
        }
        $idempotencyKey = $options['idempotency_key'] ?? null;
        if (is_string($idempotencyKey) && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        } else {
            $idempotencyKey = null;
        }
        if (isset($options['headers']) && is_array($options['headers'])) {
            foreach ($options['headers'] as $name => $value) {
                $headers[(string) $name] = (string) $value;
            }
        }
        $timeout = isset($options['timeout']) && is_numeric($options['timeout'])
            ? (float) $options['timeout'] : $this->timeout;

        // A POST or PATCH without a key may have run; only a 429 (never run) is retried.
        $replaySafe = !in_array($method, ['POST', 'PATCH'], true)
            || $idempotencyKey !== null
            || (($options['retry_safe'] ?? false) === true);

        $attempt = 0;
        while (true) {
            $request = new Request($method, $url, $headers, $payload);
            try {
                $response = $this->http->send($request, [
                    RequestOptions::HTTP_ERRORS => false,
                    RequestOptions::TIMEOUT => $timeout,
                    RequestOptions::CONNECT_TIMEOUT => min($timeout, 10.0),
                ]);
            } catch (ConnectException $e) {
                $error = self::isTimeout($e)
                    ? new TimeoutException('The request to AY Inbox timed out.', $e)
                    : new ConnectionException('Could not reach AY Inbox: ' . self::redact($e->getMessage(), $this->apiKey), $e);
                if ($replaySafe && $attempt < $this->maxRetries) {
                    ($this->sleeper)($this->backoff($attempt));
                    $attempt++;
                    continue;
                }
                throw $error;
            } catch (RequestException $e) {
                $response = $e->getResponse();
                if ($response === null) {
                    throw new ConnectionException('Could not reach AY Inbox: ' . self::redact($e->getMessage(), $this->apiKey), $e);
                }
            } catch (GuzzleException $e) {
                throw new ConnectionException('Could not reach AY Inbox: ' . self::redact($e->getMessage(), $this->apiKey), $e);
            }

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return [
                    'status' => $status,
                    'body' => self::decode($response),
                    'headers' => $response->getHeaders(),
                ];
            }

            $error = ApiException::fromResponse($status, self::decode($response), $response->getHeaders());
            $retryable = $status === 429 || ($replaySafe && in_array($status, [502, 503, 504], true));
            if ($retryable && $attempt < $this->maxRetries) {
                $wait = $error->getRetryAfter();
                ($this->sleeper)($wait !== null ? min((float) $wait, self::RETRY_AFTER_CAP) : $this->backoff($attempt));
                $attempt++;
                continue;
            }
            throw $error;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'apiKey' => '[redacted]',
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'maxRetries' => $this->maxRetries,
        ];
    }

    private function backoff(int $attempt): float
    {
        $ceiling = min(self::BACKOFF_CAP, self::BACKOFF_BASE * (2 ** $attempt));

        return $ceiling * (mt_rand() / mt_getrandmax());
    }

    private static function decode(ResponseInterface $response): mixed
    {
        $raw = (string) $response->getBody();
        if (trim($raw) === '') {
            return null;
        }
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return $raw;
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function buildQuery(array $query): string
    {
        $parts = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $value = implode(',', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $value));
            } elseif (!is_scalar($value)) {
                continue;
            }
            $parts[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }

        return implode('&', $parts);
    }

    private static function isTimeout(ConnectException $e): bool
    {
        $context = $e->getHandlerContext();
        if (($context['errno'] ?? null) === 28) {
            return true;
        }

        return stripos($e->getMessage(), 'timed out') !== false || str_contains($e->getMessage(), 'cURL error 28');
    }

    private static function redact(string $message, string $key): string
    {
        return str_replace($key, '[redacted]', $message);
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
