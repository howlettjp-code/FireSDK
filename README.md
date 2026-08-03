# FireSDK

Client libraries for the [Fire AI Inference API](https://fire.test1.prosaga.net/v1/capabilities)
— model-agnostic chat/image completions (L1), canned server-side
workflows (L2), and a data-driven, resumable multi-agent flow layer (v2).
Three languages, one shape: every method mirrors the same method across
languages (`run_flow` / `runFlow`), result-bearing calls return typed
objects with the same fields in every language, and Fire's HTTP error
codes map to the same typed-exception hierarchy everywhere.

| | Language | Package | Deps |
|---|---|---|---|
| [`python/`](python/) | Python 3.10+ | `fire-sdk` (PyPI, planned) | `requests` |
| [`php/`](php/) | PHP 8.1+ | `moduspromethean/fire-sdk` (Packagist, planned) | none (`ext-curl`, `ext-json`) |
| [`js/`](js/) | Node 18+, ESM | `@moduspromethean/fire-sdk` (npm, planned) | none (native `fetch`) |

Each subdirectory is fully self-contained — its own README, tests, and
example. Start with whichever matches your project; the concepts
(capabilities discovery, chat, species, workflows, flows, error types)
are identical across all three.

> **1.0.0 (2026-08-03) is a breaking change from 0.x.** Result-bearing
> calls now return typed objects instead of bare dicts/arrays/objects —
> see "The object model" below, and each language's README for exact
> migration notes.

## Quick orientation

- **L1 — chat / image**: `chat()`, `chatParallel()`, `image()`. A single
  or multi-model completion, billed and logged per call. Returns
  `ChatResult` / `ParallelChatResult` / `ImageResult`.
- **Species** — the model taxonomy (family → genus → species) as a
  **generated**, navigable namespace (`Species.Anthropic.CLAUDE_SONNET_4_5`
  / `Species\Anthropic::CLAUDE_SONNET_4_5` / `Species.anthropic.CLAUDE_SONNET_4_5`)
  instead of hardcoded strings. Regenerate with `scripts/generate_species.py`
  whenever the model catalog changes — see "The object model" for why
  this is data, not a class hierarchy.
- **L2 — workflows**: `runWorkflow(slug, params)` → `POST /v1/workflows/{slug}`,
  a canned server-side recipe (`image`, `article`, ...). Returns a
  deliberately thin `WorkflowResult` — each workflow defines its own
  contract, this doesn't try to model every field of every one.
- **v2 — flows**: a DAG of steps (`agent_call` + `human_gate`) executed
  asynchronously. Two ways to run one:
  - `runFlow(flowSlug: "some-saved-flow", ...)` → `POST /v2/flows/{slug}/run`
    (added 2026-08-03) — a saved flow definition's slug **is** the
    endpoint, same shape as L1's `POST /v1/workflows/{workflow}`.
    Preferred; no route/SDK change needed when a new flow is added
    server-side.
  - `runFlow(flow: {...})` → `POST /v2/flows/run` — an unsaved, ad-hoc
    spec. The only path that accepts an inline spec.
  - Both return a **`FlowRun`** — a stateful, pollable object with
    `.wait()`/`.resume()`/`.refresh()` bound to it.
- **Errors**: `FireAuthError` (401), `FireBillingError` (402 /
  balance-tier issues), `FireValidationError` (422, including
  `FLOW_QUOTA_EXCEEDED`), `FireConflictError` (409), `FireNotFoundError`
  (404 — including a private flow you don't own), `FireServerError`
  (5xx), plus a client-side `FireTimeoutError` from `waitForFlow`'s own
  poll timeout.

Full API reference: `/var/www/saga/API.md` on the Fire host, or
`GET /v1/capabilities` (no auth) at runtime.

## The object model

Result-bearing calls return typed objects — property/attribute access
(`result.content`) instead of dict/array key access — but **not** a
single inheritance tree. Three separate root families, deliberately
matching the three layers Fire itself enforces server-side
(`FirePipeline` / `WorkflowContract` / `FlowEngine`):

- **L1** (`ChatResult`, `ParallelChatResult`, `ImageResult`) — plain
  value objects, no behavior.
- **L2** (`WorkflowResult`) — thin on purpose; each workflow slug has its
  own contract, so this doesn't attempt to model every field of every
  workflow. Use `.raw`.
- **v2** (`FlowRun`, `FlowStep`) — the one type that owns behavior
  (`.wait()`, `.resume()`), because a flow run is inherently stateful and
  pollable, not a one-shot result.

Every object carries the full original response as `.raw`/`raw` — a
field a type doesn't model yet is always still reachable, so a
server-side addition never requires an SDK release just to stay usable.

**Why not a class per model, inheriting down the taxonomy
(Kingdom→Phylum→Class→Order→Genus→Species)?** That was the original
design under consideration. We didn't build it: the taxonomy is live,
server-managed data — models get onboarded, deprecated, and renamed
continuously (31 species across 13 families as of this writing, several
already retired) — so a class hierarchy baked into three SDKs would
either drift stale or demand a release every time the catalog changes.
`Species` gets the same discoverability (autocomplete, typo-proofing)
without that coupling, because it's *generated data*, regenerated and
committed deliberately, not inherited behavior. Deep inheritance is also
usually the wrong tool for "which one of N interchangeable things" —
composition (a string parameter, or here, a generated constant) scales
better than a 6-level class tree, and it's the same choice OpenAI's,
Anthropic's, and Stripe's own SDKs make for the equivalent problem.

## Getting a token

Ask whoever gave you access for a `fire_sk_...` token, or self-serve one
with a JP-issued tier code via each SDK's `redeemTierCode`/
`redeem_tier_code` — see the per-language README's "Getting a token"
section.

## Status

Public repo (`howlettjp-code/FireSDK`), not yet published to PyPI/
Packagist/npm — working toward that; for now, all three install from a
clone (see each subdirectory's README for exact install steps).
