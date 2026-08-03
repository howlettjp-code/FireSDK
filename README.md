# FireSDK

Client libraries for the [Fire AI Inference API](https://fire.test1.prosaga.net/v1/capabilities)
— model-agnostic chat completions (L1) plus a data-driven, resumable
multi-agent flow layer (v2). Three languages, one shape: every method
mirrors the same method across languages (`run_flow` / `runFlow`),
returns the parsed JSON response as a plain map/array/object (no
SDK-specific response model to learn), and maps Fire's HTTP error codes
to the same typed-exception hierarchy.

| | Language | Package | Deps |
|---|---|---|---|
| [`python/`](python/) | Python 3.10+ | `fire-sdk` (PyPI, planned) | `requests` |
| [`php/`](php/) | PHP 8.1+ | `moduspromethean/fire-sdk` (Packagist, planned) | none (`ext-curl`, `ext-json`) |
| [`js/`](js/) | Node 18+, ESM | `@moduspromethean/fire-sdk` (npm, planned) | none (native `fetch`) |

Each subdirectory is fully self-contained — its own README, tests, and
example. Start with whichever matches your project; the concepts
(capabilities discovery, chat, flows, error types) are identical across
all three.

## Quick orientation

- **L1 — chat**: `chat()` / `chatParallel()`. A single or multi-model
  completion, billed and logged per call.
- **v2 — flows**: a DAG of steps (`agent_call` + `human_gate`) executed
  asynchronously. Two ways to run one:
  - `runFlow(flowSlug: "some-saved-flow", ...)` → `POST /v2/flows/{slug}/run`
    (added 2026-08-03) — a saved flow definition's slug **is** the
    endpoint, same shape as L1's `POST /v1/workflows/{workflow}`.
    Preferred; no route/SDK change needed when a new flow is added
    server-side.
  - `runFlow(flow: {...})` → `POST /v2/flows/run` — an unsaved, ad-hoc
    spec. The only path that accepts an inline spec.
- **Errors**: `FireAuthError` (401), `FireBillingError` (402 /
  balance-tier issues), `FireValidationError` (422), `FireConflictError`
  (409), `FireNotFoundError` (404), `FireServerError` (5xx), plus a
  client-side `FireTimeoutError` from `waitForFlow`'s own poll timeout.

Full API reference: `/var/www/saga/API.md` on the Fire host, or
`GET /v1/capabilities` (no auth) at runtime.

## Getting a token

Ask whoever gave you access for a `fire_sk_...` token, or self-serve one
with a JP-issued tier code via each SDK's `redeemTierCode`/
`redeem_tier_code` — see the per-language README's "Getting a token"
section.

## Status

Public repo (`howlettjp-code/FireSDK`), not yet published to PyPI/
Packagist/npm — working toward that; for now, all three install from a
clone (see each subdirectory's README for exact install steps).
