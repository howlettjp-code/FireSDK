# FireSDK (PHP)

A small PHP client for the [Fire AI Inference API](https://fire.test1.prosaga.net/v1/capabilities)
— model-agnostic chat completions plus a data-driven, resumable
multi-agent flow layer. Zero external dependencies (just `ext-curl` and
`ext-json`, both standard); every method returns the parsed JSON response
as a plain associative array rather than a custom response object —
mirrors the [Python SDK](../python/)'s own design choice, on purpose,
since the three SDKs in this repo are meant to feel like the same client
in three languages.

```bash
composer require moduspromethean/fire-sdk
```

```php
<?php
require 'vendor/autoload.php';

use ModusPromethean\FireSdk\FireClient;

$client = new FireClient(token: 'fire_sk_...');
$result = $client->chat([['role' => 'user', 'content' => 'Hello']]);
echo $result['content'];
```

## Getting a token

Ask whoever gave you access to this SDK for a `fire_sk_...` token. If
they gave you a **tier code** instead, redeem it yourself — no token
needed to do this:

```php
[$token, $response, $client] = FireClient::redeemTierCode(
    code: 'YOUR-CODE', name: 'Your Name', email: 'you@example.test'
);
echo $token; // save this — it's shown once
```

## Start here: discover the API

```php
$caps = $client->capabilities();   // no token required
print_r(array_keys($caps['planes']));   // data, workflows, service_control, flows, billing, governance
print_r($caps['models']);               // every active model + its `strengths`
```

## Chat

```php
$client->chat(
    messages: [['role' => 'user', 'content' => 'What is 2+2?']],
    systemPrompt: 'Be concise.',
    speciesName: 'claude-sonnet-4-5',   // omit for the default model
    temperature: 0.7,
);

$client->chatParallel([
    ['messages' => [['role' => 'user', 'content' => '...']], 'species_name' => 'gpt-4o'],
    ['messages' => [['role' => 'user', 'content' => '...']], 'species_name' => 'claude-sonnet-4-5'],
]);
```

Note: models tagged `"reasoning"` in their `strengths` (check
`$client->models()`) spend part of their `maxTokens` budget on hidden
reasoning before any visible output — Fire silently raises a too-low
`maxTokens` to a safe floor server-side so you never get billed for an
empty response.

## v2 — multi-agent flows

A **flow** is a DAG of steps — `agent_call` (a model call using a named,
reusable "agent config": model + temperature + system prompt) and
`human_gate` (pauses the run for a person to weigh in). Flows run
**asynchronously** — starting or resuming one returns immediately with
`status: "pending"`/`"running"`, and you poll (or use `waitForFlow`)
until it lands on `completed`, `failed`, or `awaiting_human`.

```php
// A saved flow (a fire_flow_definitions slug) is callable directly — the
// slug IS the endpoint (POST /v2/flows/{slug}/run).
$run = $client->runFlow(flowSlug: 'triad', input: ['prompt_package' => 'your question here']);
$run = $client->waitForFlow($run['run_id']);

if ($run['status'] === 'awaiting_human') {
    $run = $client->resumeFlowRun($run['run_id'], 'review', ['note' => 'go with the contrarian take']);
    $run = $client->waitForFlow($run['run_id']);
}

echo $run['output']['content'];
```

An unsaved, ad-hoc spec (no slug) still goes through `POST /v2/flows/run`
— the only path that accepts an inline `flow` body:

```php
$run = $client->runFlow(flow: ['steps' => [/* ... */]], input: [/* ... */]);
```

### Agent configs / flow definitions (CRUD)

```php
$client->createAgentConfig(slug: 'hot', label: 'Hot', speciesName: 'claude-sonnet-4-5', temperature: 0.9);
$client->createFlowDefinition(slug: 'my-flow', label: 'My Flow', spec: ['steps' => [/* ... */]]);
```

## Errors

Every failure raises a typed exception under `ModusPromethean\FireSdk\Exceptions`,
each carrying `->statusCode`, `->errorCode`, and the full parsed
`->response` body:

| Exception | When |
|---|---|
| `FireAuthError` | 401 |
| `FireBillingError` | 402, or `PRICING_TIER_UNAVAILABLE`/`INSUFFICIENT_BALANCE` |
| `FireValidationError` | 422 (field errors, or a flow spec's `details`) |
| `FireConflictError` | 409 (e.g. resuming a run that isn't awaiting human input) |
| `FireNotFoundError` | 404 |
| `FireServerError` | 5xx |
| `FireTimeoutError` | `waitForFlow()`'s own client-side poll timeout (not a Fire API error) |

## Development

```bash
composer install
composer test
```
