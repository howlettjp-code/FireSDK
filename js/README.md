# FireSDK (JS)

A small JavaScript client for the [Fire AI Inference API](https://fire.test1.prosaga.net/v1/capabilities)
— model-agnostic chat completions plus a data-driven, resumable
multi-agent flow layer. Zero dependencies (native `fetch`, Node 18+);
every method returns the parsed JSON response as a plain object rather
than a custom response class — mirrors the [Python](../python/) and
[PHP](../php/) SDKs' own design choice, on purpose, since the three SDKs
in this repo are meant to feel like the same client in three languages.
ESM only (`"type": "module"`).

```bash
npm install @moduspromethean/fire-sdk
```

```js
import { FireClient } from '@moduspromethean/fire-sdk';

const client = new FireClient({ token: 'fire_sk_...' });
const result = await client.chat([{ role: 'user', content: 'Hello' }]);
console.log(result.content);
```

## Getting a token

Ask whoever gave you access to this SDK for a `fire_sk_...` token. If
they gave you a **tier code** instead, redeem it yourself — no token
needed to do this:

```js
import { FireClient } from '@moduspromethean/fire-sdk';

const { token, client } = await FireClient.redeemTierCode('YOUR-CODE', 'Your Name', 'you@example.test');
console.log(token); // save this — it's shown once
```

## Start here: discover the API

```js
const caps = await client.capabilities();   // no token required
console.log(Object.keys(caps.planes));      // data, workflows, service_control, flows, billing, governance
console.log(caps.models);                   // every active model + its `strengths`
```

## Chat

```js
await client.chat(
  [{ role: 'user', content: 'What is 2+2?' }],
  { systemPrompt: 'Be concise.', speciesName: 'claude-sonnet-4-5', temperature: 0.7 },
);

await client.chatParallel([
  { messages: [{ role: 'user', content: '...' }], species_name: 'gpt-4o' },
  { messages: [{ role: 'user', content: '...' }], species_name: 'claude-sonnet-4-5' },
]);
```

Note: models tagged `"reasoning"` in their `strengths` (check
`client.models()`) spend part of their `maxTokens` budget on hidden
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

```js
// A saved flow (a fire_flow_definitions slug) is callable directly — the
// slug IS the endpoint (POST /v2/flows/{slug}/run).
let run = await client.runFlow({ flowSlug: 'triad', input: { prompt_package: 'your question here' } });
run = await client.waitForFlow(run.run_id);

if (run.status === 'awaiting_human') {
  run = await client.resumeFlowRun(run.run_id, 'review', { note: 'go with the contrarian take' });
  run = await client.waitForFlow(run.run_id);
}

console.log(run.output.content);
```

An unsaved, ad-hoc spec (no slug) still goes through `POST /v2/flows/run`
— the only path that accepts an inline `flow` body:

```js
const run = await client.runFlow({ flow: { steps: [/* ... */] }, input: { /* ... */ } });
```

### Agent configs / flow definitions (CRUD)

```js
await client.createAgentConfig('hot', 'Hot', { speciesName: 'claude-sonnet-4-5', temperature: 0.9 });
await client.createFlowDefinition('my-flow', 'My Flow', { steps: [/* ... */] });
```

## Errors

Every failure throws a typed error exported from `@moduspromethean/fire-sdk/errors`,
each carrying `.statusCode`, `.errorCode`, and the full parsed `.response` body:

| Error | When |
|---|---|
| `FireAuthError` | 401 |
| `FireBillingError` | 402, or `PRICING_TIER_UNAVAILABLE`/`INSUFFICIENT_BALANCE` |
| `FireValidationError` | 422 (field errors, or a flow spec's `details`) |
| `FireConflictError` | 409 (e.g. resuming a run that isn't awaiting human input) |
| `FireNotFoundError` | 404 |
| `FireServerError` | 5xx |
| `FireTimeoutError` | `waitForFlow()`'s own client-side poll timeout (not a Fire API error) |

## Development

No dev dependencies — tests run on Node's built-in test runner.

```bash
npm test
```
