# AY Inbox PHP library

The official PHP client for the [AY Inbox REST API](https://docs.ayinbox.com/api/). AY Inbox is a hosted cold email platform: it sends cold email at a paced daily volume, brings every reply into one inbox (Unibox), drafts answers from a per-campaign brain, and can offer a meeting time from Cal.com or Calendly.

AY Inbox is a hosted service. This repository holds its PHP client library. The service itself is not open source.

Access to the hosted service is invite only for now: ask at team@ayinbox.com.

## Requirements

- PHP 8.1 or later
- Guzzle 7 (installed with the package)

## Install

Not on Packagist yet. Until it is, install it from GitHub by adding the repository to your `composer.json`:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/ayinbox/ayinbox-php"}
    ],
    "require": {
        "ayinbox/ayinbox": "dev-main"
    }
}
```

Then run `composer update ayinbox/ayinbox`.

## Quick start

```php
<?php
require 'vendor/autoload.php';

use AyInbox\AyInbox;
use AyInbox\Exception\ConflictException;

$client = new AyInbox(); // reads AYINBOX_API_KEY

$me = $client->me->get();
echo "Signed in as {$me['email']}\n";

foreach ($client->campaigns->iterate(['status' => 'active']) as $campaign) {
    echo "{$campaign['name']} ({$campaign['status']})\n";
}

try {
    $contacts = $client->contacts->create(
        ['email' => 'lee@example.com', 'first_name' => 'Lee'],
        ['idempotency_key' => 'import-lee-2026-11-13'],
    );
    echo "Contact {$contacts[0]['id']}\n";
} catch (ConflictException $e) {
    echo "Conflict: {$e->getErrorCode()} (request {$e->getRequestId()})\n";
}
```

## Configuration

```php
$client = new AyInbox(
    apiKey: 'aybox_...',                        // default: AYINBOX_API_KEY
    baseUrl: 'https://api.ayinbox.com/v1',      // default: AYINBOX_BASE_URL, then this
    timeout: 30.0,                              // seconds per attempt
    maxRetries: 2,                              // 0 disables retries
    httpClient: new GuzzleHttp\Client([...]),   // any GuzzleHttp\ClientInterface
);
```

API keys start with `aybox_` and OAuth access tokens with `ayat_`. Both are sent as `Authorization: Bearer <credential>`. The client never prints the key: `var_dump` and `print_r` show it as `[redacted]`, and no exception message contains it.

## Resources

Responses are decoded JSON as associative arrays, so fields the API adds later are there without an upgrade.

| Call | HTTP | Scope |
|---|---|---|
| `$client->me->get()` | `GET /me` | none |
| `$client->campaigns->list($params)` / `iterate($params)` | `GET /campaigns` | `READ_CAMPAIGNS` |
| `$client->campaigns->get($id)` | `GET /campaigns/{id}` | `READ_CAMPAIGNS` |
| `$client->campaigns->create($params)` | `POST /campaigns` | `WRITE_CAMPAIGNS` |
| `$client->campaigns->pause($id)` | `POST /campaigns/{id}/stop` | `WRITE_CAMPAIGNS`, `START_CAMPAIGNS` or `SEND_CAMPAIGNS` |
| `$client->contacts->list($filters, $params)` / `iterate(...)` | `POST /contacts/search` | `READ_CONTACTS` |
| `$client->contacts->get($id)` | `GET /contacts/{id}` | `READ_CONTACTS` |
| `$client->contacts->create($contactOrList)` | `POST /contacts` | `WRITE_CONTACTS` |
| `$client->contacts->update($id, $params)` | `PATCH /contacts/{id}` | `WRITE_CONTACTS` |
| `$client->contacts->delete($id)` | `DELETE /contacts/{id}` | `WRITE_CONTACTS` |
| `$client->mailboxes->list($params)` / `iterate($params)` | `GET /emails` | `READ_EMAILS` |
| `$client->unibox->listThreads($params)` / `iterateThreads($params)` | `GET /unibox` | `READ_UNIBOX` |
| `$client->unibox->getThread($threadId, $params)` / `iterateThread(...)` | `GET /unibox/thread` | `READ_UNIBOX` |
| `AyInbox\Webhook::verifySignature(...)` | local | none |

`contacts->create` takes one contact or a list of up to 10,240 and always returns a list. An address that already exists is updated, not duplicated.

Every method takes a last `$options` array: `idempotency_key`, `headers` (extra headers, for example your own `X-Request-Id`) and `timeout` (seconds).

### Every other route

Everything else in the [API reference](https://docs.ayinbox.com/api/) is reachable through `request`, with the same auth, retries and errors:

```php
$count = $client->request('GET', '/unibox/count');

$template = $client->request('POST', '/templates', [
    'body' => ['name' => 'Follow-up', 'body_plain' => 'Hi {{first_name}}'],
    'idempotency_key' => 'template-follow-up',
]);

// Explicit JSON null clears a field.
$client->request('PATCH', "/campaigns/{$id}", ['body' => ['end_date' => null]]);
```

`request` returns the decoded JSON, the raw text for a non-JSON body, or `null` for an empty one. Options: `query`, `body`, `idempotency_key`, `headers`, `timeout`.

## Pagination

`list` methods return an `AyInbox\Page` with `data`, `nextCursor`, `hasMore`, `total` (only on some lists) and `raw` (the whole response, for fields such as `counts` on a contact search). Pass `nextCursor` back as `cursor` for the next page:

```php
$page = $client->campaigns->list(['limit' => 50]);
$next = $client->campaigns->list(['limit' => 50, 'cursor' => $page->nextCursor]);
```

`iterate` walks every page for you and yields one row at a time. For any other paginated route, use `paginate`:

```php
foreach ($client->paginate('/suppressions', ['limit' => 200]) as $suppression) {
    // ...
}
```

A 429 in the middle of a walk is waited out and retried, not returned as a partial list. Treat cursors as opaque. Recommended `limit` is 1 to 100.

## Errors

Every non-2xx answer throws a subclass of `AyInbox\Exception\ApiException`, which carries `getStatus()`, `getErrorCode()` (the API's stable `code`, the thing to branch on), `getMessage()`, `getReason()`, `getRequestId()`, `getBody()`, `getHeaders()` and `getRetryAfter()`.

| Exception | Status |
|---|---|
| `BadRequestException` (a `ValidationException`) | 400 |
| `AuthenticationException` | 401 |
| `PaymentRequiredException` | 402 |
| `PermissionDeniedException` | 403 |
| `NotFoundException` | 404 |
| `ConflictException` | 409 |
| `UnprocessableException` (a `ValidationException`) | 422 |
| `RateLimitException` | 429 |
| `InternalServerException` | 500, 501, 502, 504 and other 5xx |
| `ServiceUnavailableException` | 503 |
| `ConnectionException` / `TimeoutException` | no answer |

All of them extend `AyInbox\Exception\AyInboxException`. A webhook that fails verification throws `SignatureVerificationException`.

```php
use AyInbox\Exception\ApiException;
use AyInbox\Exception\NotFoundException;

try {
    $client->campaigns->get($id);
} catch (NotFoundException $e) {
    // gone or not visible to this key
} catch (ApiException $e) {
    error_log("{$e->getStatus()} {$e->getErrorCode()} request={$e->getRequestId()}");
}
```

## Retries and timeouts

The client retries 429, 502, 503, 504 and connection failures, up to `maxRetries` times (default 2). It waits for `Retry-After` when the server sends one (capped at 120 seconds), otherwise an exponential backoff with full jitter (0.5 s base, 8 s cap). It never retries a 500 or a 409.

A `POST` or `PATCH` without an idempotency key is retried only on 429, which the server never runs. The client does not generate idempotency keys for you: pass one to make a write safe to retry. `contacts->list` is a `POST` that only reads, so it is retried like a `GET`.

`timeout` applies to each attempt.

## Idempotency

Pass `idempotency_key` on any mutating call. It goes out as the `Idempotency-Key` header and is reused on every retry of that call.

- Same key and same request within 24 hours: the original response is replayed.
- Same key with a different request: `409 conflict`.
- First attempt ended in a 5xx or never finished: `409 idempotency_outcome_unknown` for 24 hours. Check whether the change happened before trying again with a new key.
- Responses that hold a secret shown once (a new webhook endpoint, a rotated secret) are not replayed: a retry answers `409 idempotent_secret_withheld`.

## Webhooks

Each delivery carries `X-Ayinbox-Signature: t=<unix-seconds>,v1=<hex>`. Verify it against the raw body, before parsing it:

```php
use AyInbox\Exception\SignatureVerificationException;
use AyInbox\Webhook;

$payload = file_get_contents('php://input');
$header = $_SERVER['HTTP_X_AYINBOX_SIGNATURE'] ?? '';

try {
    $event = Webhook::constructEvent($payload, $header, getenv('AYINBOX_WEBHOOK_SECRET'));
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}
```

The secret is used whole, including its `whsec_` prefix. Signatures older or newer than 300 seconds are refused (the fifth argument changes that). `Webhook::verifySignature` returns a boolean instead of throwing. Deduplicate on `$event['id']`, which stays the same across retries.

## Calls that send email

These calls put real email on the wire:

- `campaigns.start` (`POST /campaigns/{id}/start`): needs `START_CAMPAIGNS` or `SEND_CAMPAIGNS`.
- `campaigns.update` with `status: "active"` (and `completed` or `paused_undeliverable`): needs `START_CAMPAIGNS` or `SEND_CAMPAIGNS` in addition to `WRITE_CAMPAIGNS`.
- `campaigns.leads.resume` on a finished campaign: needs `START_CAMPAIGNS` or `SEND_CAMPAIGNS`.
- `unibox.reply` and `unibox.approveAgentDraft`: need `WRITE_UNIBOX`. A key with `WRITE_UNIBOX` can send replies from any mailbox it can reach.
- Through `request`: `POST /unibox/compose`, `POST /emails/{id}/send`, `POST /campaigns/{id}/test-email`, the first-email review queue (`/campaigns/{id}/reviews/...`), placement tests: `SEND_CAMPAIGNS` (compose: `WRITE_UNIBOX`).

In this library, all of these go through `$client->request(...)`.

Other things to know:

- Adding contacts can send mail without calling start: contacts added to an `active` campaign are queued; a `completed` campaign starts again on its own when leads are added; a contact that comes to match a segment linked to a sending campaign is enrolled.
- `START_CAMPAIGNS` starts, resumes and pauses campaigns and nothing else. `SEND_CAMPAIGNS` covers everything `START_CAMPAIGNS` covers plus the other sending actions. `WRITE_CAMPAIGNS` alone edits drafts and pauses, and cannot start anything.
- A key for a model that reads the inbox should use the "Agent: read and draft" preset (84574239): every read scope plus `AI_DRAFT`, with nothing that writes or sends.
- Some calls spend workspace credits (AI drafts, research, enrichment, paid tools). They answer `402 insufficient_credits` when the balance is out.
- Retrying a send without an idempotency key can send twice. Pass an idempotency key on every mutating call you might retry.

## Examples

`examples/list_campaigns.php` lists every campaign. `examples/verify_webhook.php` is a webhook receiver you can run with `php -S`.

## Development

```sh
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

The tests run against Guzzle's `MockHandler` with fixtures in `tests/fixtures`. They need no network and no key.

## Links

- API reference: https://docs.ayinbox.com/api/
- Docs: https://docs.ayinbox.com/
- Dashboard: https://app.ayinbox.com
- Contact: team@ayinbox.com

## License

MIT. See [LICENSE](LICENSE).
