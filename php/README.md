# FireSDK (PHP)

A small PHP client for the [Fire AI Inference API](https://fire.test1.prosaga.net/v1/capabilities)
— model-agnostic chat/image completions (L1), canned server-side
workflows (L2), and a data-driven, resumable multi-agent flow layer (v2).
Zero external dependencies (just `ext-curl` and `ext-json`, both
standard).

```bash
composer require moduspromethean/fire-sdk
```

```php
<?php
require 'vendor/autoload.php';

use ModusPromethean\FireSdk\FireClient;

$client = new FireClient(token: 'fire_sk_...');
$result = $client->chat([['role' => 'user', 'content' => 'Hello']]);
echo $result->content;
```

> **1.0.0 (2026-08-03) is a breaking change from 0.x.** Result-bearing
> calls (`chat`, `chatParallel`, `image`, `runWorkflow`, `runFlow` and
> friends) now return typed objects — property access (`$result->content`)
> instead of array access (`$result['content']`). Every object still
> carries the full original response as `->raw`, so nothing is lost, only
> the primary access pattern changed. See "Why typed objects" below for
> the reasoning. Discovery calls (`capabilities`, `status`, `models`,
> `usage`) and CRUD metadata (agent-configs, flow-definitions) are
> unaffected — those still return plain associative arrays.

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
$caps = $client->capabilities();   // no token required, still a plain array
print_r(array_keys($caps['planes']));   // data, workflows, service_control, flows, billing, governance
print_r($caps['models']);               // every active model + its `strengths`
print_r($caps['account_setup']);        // ['url' => '.../portal', ...] — where a human sets up billing
```

## Chat

```php
$result = $client->chat(
    messages: [['role' => 'user', 'content' => 'What is 2+2?']],
    systemPrompt: 'Be concise.',
    speciesName: 'claude-sonnet-4-5',   // omit for the default model — or use Species, below
    temperature: 0.7,
);
echo $result->content, ' ', $result->priceUsd;

$parallel = $client->chatParallel([
    ['messages' => [['role' => 'user', 'content' => '...']], 'species_name' => 'gpt-4o'],
    ['messages' => [['role' => 'user', 'content' => '...']], 'species_name' => 'claude-sonnet-4-5'],
]);
foreach ($parallel->results as $item) {
    echo $item->ok ? $item->content : $item->error;
}
```

Note: models tagged `"reasoning"` in their `strengths` (check
`$client->models()`) spend part of their `maxTokens` budget on hidden
reasoning before any visible output — Fire silently raises a too-low
`maxTokens` to a safe floor server-side so you never get billed for an
empty response.

## Image

```php
$result = $client->image('a lighthouse at dawn, painterly americana style', n: 1, size: '1024x1024');
echo $result->images[0]->url ?? $result->images[0]->b64, ' ', $result->priceUsd;
```

## Species — the model taxonomy, as a navigable registry

Fire organizes every model under a biological taxonomy (family → genus →
species). `Species\*` is a **generated** set of classes
(`scripts/generate_species.py`, run against `GET /v1/capabilities`,
checked in — not fetched at request time), one class per family, that
turns that taxonomy into discoverable, typo-proof constants:

```php
use ModusPromethean\FireSdk\Species\Anthropic;
use ModusPromethean\FireSdk\Species\Openai;

$client->chat([...], speciesName: Anthropic::CLAUDE_SONNET_4_5);
$client->image('...', speciesName: Openai::GPT_IMAGE_1);
```

This is deliberately **not** a class per model with inheritance down the
full family→genus→species chain — the catalog changes constantly (models
get onboarded/deprecated/renamed), and a deep class hierarchy would mean
this SDK going stale or needing a release every time it does. A generated
namespace is the same discoverability without that coupling: regenerate
and commit when the catalog changes, nothing more. `speciesName` still
takes a plain string too — `Species\*` is a convenience, never required.

## L2 — workflows

Canned, server-side, multi-step recipes (`image`, `article` today — see
`GET /v1/capabilities`'s `planes.workflows` for the current list). Each
workflow defines its own request/response contract, so the result is
deliberately thin:

```php
$result = $client->runWorkflow('article', ['source_text' => '...', 'seed' => '...']);
echo $result->workflow;   // "article"
print_r($result->raw);    // the workflow's actual (workflow-specific) response
```

`WorkflowResult` is a **separate root** from `ChatResult`/`ImageResult` on
purpose — L2 is architecturally a different layer server-side
(`WorkflowContract`, not `FirePipeline` directly), with a contract that
varies per workflow slug, not a variant of a one-shot chat/image call.

## v2 — multi-agent flows

A **flow** is a DAG of steps — `agent_call` (a model call using a named,
reusable "agent config": model + temperature + system prompt) and
`human_gate` (pauses the run for a person to weigh in). Flows run
**asynchronously** — starting or resuming one returns immediately with
`status: "pending"`/`"running"`, and you poll (or use `->wait()`) until it
lands on `completed`, `failed`, or `awaiting_human`.

`runFlow`/`getFlowRun`/`resumeFlowRun`/`waitForFlow` all return a
**`FlowRun`** — the one typed object in this SDK that owns behavior
(`->wait()`, `->resume()`, `->refresh()`) rather than being an immutable
snapshot, because a flow run is inherently stateful and pollable, not a
one-shot result. `FlowRun` is its own root too — v2 is a third layer
alongside L1 and L2, not a variant of either.

### The bundled example: "triad"

A reference flow is already set up on Fire's test1 environment: two
"hot" agents (one agreeable, one contrarian) answer your question in
parallel, you review both, then a "cool" synthesizer writes the final
answer.

```bash
FIRE_TOKEN=fire_sk_... php examples/triad_example.php "Should remote work be the default?"
```

Or directly:

```php
$run = $client->runFlow(flowSlug: 'triad', input: ['prompt_package' => 'your question here']);
$run->wait();   // mutates $run in place and returns it

if ($run->status === 'awaiting_human') {
    $gate = $run->gatingStep();
    echo $gate->promptForHuman;
    echo 'Agreeable: ', $gate->context['hot_1'];
    echo 'Contrarian: ', $gate->context['hot_2'];

    $run->resume($gate->stepKey, ['note' => 'your guidance']);
    $run->wait();
}

echo $run->output['content'];   // the cool synthesizer's final answer — still an array, output shape varies
echo $run->totalCostUsd;        // real, billed cost across all 3 agent calls
```

### Building your own flow

Reusable pieces first:

```php
$client->createAgentConfig(
    slug: 'my-hot-agent', label: 'My Hot Agent',
    speciesName: Anthropic::CLAUDE_SONNET_4_5, systemPrompt: '...', temperature: 0.9,
);
```

Then either save a reusable flow definition (`createFlowDefinition`) or
run one inline, without saving anything:

```php
$spec = ['steps' => [
    ['step_key' => 'a', 'kind' => 'agent_call', 'agent_config' => 'my-hot-agent',
     'depends_on' => [], 'is_output' => true,
     'prompt' => ['messages' => [['role' => 'user', 'content' => '{{input.question}}']]]],
]];
$run = $client->runFlow(flow: $spec, input: ['question' => '...']);
```

A saved flow defaults to public (discoverable via `listFlowDefinitions`/
`GET /v2/flows` by anyone, runnable by anyone) — your account owns it
either way, and only the owner can edit/deactivate it regardless of
visibility. Pass `isPublic: false` to `createFlowDefinition` to keep it
private instead. External (paying) accounts are capped at 10 custom flow
definitions by default; deactivating one frees a slot.

`{{token.path}}` inside a step's message content resolves against
`input.*` and any already-completed step's `{{step_key.output.content}}`
/ `{{step_key.human_input.*}}`. See `examples/triad_example.php` and
`src/FireClient.php`'s docblocks for the full shape — or just inspect
`$client->getFlowDefinition('triad')` to see a real one (still a plain
array — flow *definitions* are metadata/CRUD, not a call result).

### Saving bandwidth while polling

Pass `verbosity: 'compact'` to `runFlow`, `getFlowRun`, or
`resumeFlowRun` if you only care about the final answer and don't want
every step's full content in every poll response:

```php
$run = $client->getFlowRun($runId, verbosity: 'compact');
// $run->steps carry only stepKey/kind/status/dependsOn/error — no output/context
```

Nothing about this affects what's actually stored or billed — Fire logs
every model call in full regardless; this only trims the HTTP response.

## Errors

Every failure raises a typed exception under `ModusPromethean\FireSdk\Exceptions`,
each carrying `->statusCode`, `->errorCode`, and the full parsed
`->response` body:

| Exception | When |
|---|---|
| `FireAuthError` | 401 |
| `FireBillingError` | 402, or `PRICING_TIER_UNAVAILABLE`/`INSUFFICIENT_BALANCE` |
| `FireValidationError` | 422 (field errors, a flow spec's `details`, or `FLOW_QUOTA_EXCEEDED`) |
| `FireConflictError` | 409 (e.g. resuming a run that isn't awaiting human input) |
| `FireNotFoundError` | 404 (unknown slug/run id, or a private flow you don't own) |
| `FireServerError` | 5xx |
| `FireTimeoutError` | `waitForFlow()`/`FlowRun::wait()`'s own client-side poll timeout (not a Fire API error) |

## Why typed objects (and why L1/L2/v2 don't share a base class)

The original design considered a class hierarchy mirroring Fire's model
taxonomy end to end (a class per family/genus/species, `chat()` living on
the leaf class). We didn't build that — the taxonomy is live, server-
managed data (models get onboarded, deprecated, renamed continuously),
so a class hierarchy would either drift stale or demand constant releases
just to track it. `Species\*` (above) gets the discoverability without
that coupling: it's generated data, not inherited behavior.

What *did* seem worth doing: typed results instead of bare arrays, so
`$result->content` beats `$result['content']` with real autocomplete and
typo safety. But L1 (`ChatResult`/`ImageResult`), L2 (`WorkflowResult`),
and v2 (`FlowRun`) are three separate root types, not one hierarchy —
they mirror three genuinely different layers Fire enforces server-side
(`FirePipeline` / `WorkflowContract` / `FlowEngine`), with different
contracts and, for `FlowRun`, genuinely different behavior (stateful and
pollable vs. one-shot). Forcing them under one base class would just be
inheritance for organization's sake, not because they share behavior.

## Development

```bash
composer install
composer test
```

Regenerate `src/Species/*.php` (and the Python/JS equivalents) after a
model catalog change: `python3 ../scripts/generate_species.py` from this
directory, or `python3 scripts/generate_species.py` from the repo root.
