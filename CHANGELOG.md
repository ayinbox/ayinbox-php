# Changelog

## 0.1.0 (unreleased)

First release.

- `AyInbox` client with API key and base URL from the environment, timeouts, retries with backoff that honour `Retry-After`, and an injectable Guzzle client.
- Resources: `me->get`, `campaigns->list/get/create/pause`, `contacts->list/get/create/update/delete`, `mailboxes->list`, `unibox->listThreads/getThread`, each list with an `iterate` helper.
- Generic `request`, `requestPage` and `paginate` for every other route.
- Typed exceptions for every status the API answers, including the 429 that carries no `code`.
- `Webhook::verifySignature` and `Webhook::constructEvent`.
