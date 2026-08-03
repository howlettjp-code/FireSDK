# FireSDK (Python)

A small Python client for the [Fire AI Inference API](https://fire.test1.prosaga.net/v1/capabilities)
— model-agnostic chat/image completions (L1), canned server-side
workflows (L2), and a data-driven, resumable multi-agent flow layer (v2).
Built for LLM-agent and test-script use: synchronous, minimal
dependencies (just `requests`).

```bash
pip install -e .          # from a clone of this repo
```

```python
from fire_sdk import FireClient

client = FireClient(token="fire_sk_...")
result = client.chat([{"role": "user", "content": "Hello"}])
print(result.content)
```

> **1.0.0 (2026-08-03) is a breaking change from 0.x.** Result-bearing
> calls (`chat`, `chat_parallel`, `image`, `run_workflow`, `run_flow` and
> friends) now return typed objects — attribute access (`result.content`)
> instead of dict access (`result["content"]`). Every object still
> carries the full original response as `.raw`, so nothing is lost, only
> the primary access pattern changed. See "Why typed objects" below for
> the reasoning, and search-and-replace `["x"]` → `.x` on the fields
> you use if you're migrating. Discovery calls (`capabilities`, `status`,
> `models`, `usage`) and CRUD metadata (agent-configs, flow-definitions)
> are unaffected — those still return plain dicts.

## Getting a token

Ask whoever gave you access to this SDK for a `fire_sk_...` token. If
they gave you a **tier code** instead, redeem it yourself — no token
needed to do this:

```python
from fire_sdk import FireClient

token, response, client = FireClient.redeem_tier_code(
    code="YOUR-CODE", name="Your Name", email="you@example.test"
)
print(token)  # save this — it's shown once
```

## Start here: discover the API

```python
caps = client.capabilities()   # no token required, still a plain dict
print(caps["planes"].keys())   # ('data', 'workflows', 'service_control', 'flows', 'billing', 'governance')
print(caps["models"])          # every active model + its `strengths`
print(caps["account_setup"])   # {"url": ".../portal", ...} — where a human sets up billing
```

## Chat

```python
result = client.chat(
    [{"role": "user", "content": "What is 2+2?"}],
    system_prompt="Be concise.",
    species_name="claude-sonnet-4-5",   # omit for the default model — or use Species, below
    temperature=0.7,
)
print(result.content, result.price_usd)

parallel = client.chat_parallel([
    {"messages": [{"role": "user", "content": "..."}], "species_name": "gpt-4o"},
    {"messages": [{"role": "user", "content": "..."}], "species_name": "claude-sonnet-4-5"},
])
for item in parallel.results:
    print(item.content if item.ok else item.error)
```

Note: models tagged `"reasoning"` in their `strengths` (check
`client.models()`) spend part of their `max_tokens` budget on hidden
reasoning before any visible output — Fire silently raises a too-low
`max_tokens` to a safe floor server-side so you never get billed for an
empty response, but if you're passing a very tight budget on purpose,
know that it may come back larger than you asked for.

## Image

```python
result = client.image("a lighthouse at dawn, painterly americana style", n=1, size="1024x1024")
print(result.images[0].url or result.images[0].b64, result.price_usd)
```

## Species — the model taxonomy, as a navigable registry

Fire organizes every model under a biological taxonomy (family → genus →
species). `Species` is a **generated** registry (`scripts/generate_species.py`,
run against `GET /v1/capabilities`, checked in — not fetched at import
time) that turns that taxonomy into discoverable, typo-proof constants,
one nested class per family:

```python
from fire_sdk import Species

client.chat([...], species_name=Species.Anthropic.CLAUDE_SONNET_4_5)
client.image("...", species_name=Species.Openai.GPT_IMAGE_1)
```

This is deliberately **not** a class per model with inheritance down the
full family→genus→species chain — the catalog changes constantly (models
get onboarded/deprecated/renamed), and a deep class hierarchy would mean
this SDK going stale or needing a release every time it does. A generated
namespace is the same discoverability without that coupling: regenerate
and commit when the catalog changes, nothing more. `species_name` still
takes a plain string too — `Species` is a convenience, never required.

## L2 — workflows

Canned, server-side, multi-step recipes (`image`, `article` today — see
`GET /v1/capabilities`'s `planes.workflows` for the current list). Each
workflow defines its own request/response contract, so the result is
deliberately thin:

```python
result = client.run_workflow("article", source_text="...", seed="...")
print(result.workflow)   # "article"
print(result.raw)        # the workflow's actual (workflow-specific) response
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
`status: "pending"`/`"running"`, and you poll (or use `.wait()`) until it
lands on `completed`, `failed`, or `awaiting_human`.

`run_flow`/`get_flow_run`/`resume_flow_run`/`wait_for_flow` all return a
**`FlowRun`** — the one typed object in this SDK that owns behavior
(`.wait()`, `.resume()`, `.refresh()`) rather than being a frozen
snapshot, because a flow run is inherently stateful and pollable, not a
one-shot result. `FlowRun` is its own root too — v2 is a third layer
alongside L1 and L2, not a variant of either.

### The bundled example: "triad"

A reference flow is already set up on Fire's test1 environment: two
"hot" agents (one agreeable, one contrarian) answer your question in
parallel, you review both, then a "cool" synthesizer writes the final
answer.

```bash
FIRE_TOKEN=fire_sk_... python3 examples/triad_example.py "Should remote work be the default?"
```

Or directly:

```python
run = client.run_flow(flow_slug="triad", input={"prompt_package": "your question here"})
run.wait()   # mutates `run` in place and returns it — `run2 = run.wait()` also works, run2 is run

if run.status == "awaiting_human":
    gate = run.gating_step()
    print(gate.prompt_for_human)
    print("Agreeable:", gate.context["hot_1"])
    print("Contrarian:", gate.context["hot_2"])

    run.resume(gate.step_key, {"note": "your guidance"})
    run.wait()

print(run.output["content"])   # the cool synthesizer's final answer — still a dict, workflow/step output shape varies
print(run.total_cost_usd)      # real, billed cost across all 3 agent calls
```

### Building your own flow

Reusable pieces first:

```python
client.create_agent_config(
    "my-hot-agent", "My Hot Agent",
    species_name=Species.Anthropic.CLAUDE_SONNET_4_5, system_prompt="...", temperature=0.9,
)
```

Then either save a reusable flow definition (`create_flow_definition`) or
run one inline, without saving anything:

```python
spec = {
    "steps": [
        {"step_key": "a", "kind": "agent_call", "agent_config": "my-hot-agent",
         "depends_on": [], "is_output": True,
         "prompt": {"messages": [{"role": "user", "content": "{{input.question}}"}]}},
    ]
}
run = client.run_flow(flow=spec, input={"question": "..."})
```

A saved flow defaults to public (discoverable via `list_flow_definitions`/
`GET /v2/flows` by anyone, runnable by anyone) — your account owns it
either way, and only the owner can edit/deactivate it regardless of
visibility. Pass `is_public=False` to `create_flow_definition` to keep it
private instead. External (paying) accounts are capped at 10 custom flow
definitions by default; deactivating one frees a slot.

`{{token.path}}` inside a step's message content resolves against
`input.*` and any already-completed step's `{{step_key.output.content}}`
/ `{{step_key.human_input.*}}`. See `examples/triad_example.py` and
`fire_sdk/client.py`'s docstrings for the full shape — or just inspect
`client.get_flow_definition("triad")` to see a real one (still a plain
dict — flow *definitions* are metadata/CRUD, not a call result).

### Saving bandwidth while polling

Pass `verbosity="compact"` to `run_flow`, `get_flow_run`, or
`resume_flow_run` if you only care about the final answer and don't want
every step's full content in every poll response:

```python
run = client.get_flow_run(run_id, verbosity="compact")
# run.steps carry only step_key/kind/status/depends_on/error — no output/context
```

Nothing about this affects what's actually stored or billed — Fire logs
every model call in full regardless; this only trims the HTTP response.

## Error handling

Every non-2xx response raises a typed exception carrying Fire's
`error_code`/`description` (or Laravel's default validation shape) as
`.response`:

```python
from fire_sdk import FireAuthError, FireBillingError, FireConflictError, FireValidationError

try:
    client.resume_flow_run(run_id, "wrong_step", {"note": "..."})
except FireConflictError as e:
    print(e.error_code, e.response)   # FLOW_RESUME_CONFLICT
```

| Exception | When |
|---|---|
| `FireAuthError` | 401 — bad/missing/under-scoped token |
| `FireBillingError` | 402/`PRICING_TIER_UNAVAILABLE`/`INSUFFICIENT_BALANCE` |
| `FireValidationError` | 422 — bad request, an invalid flow spec (`.response["details"]`), or `FLOW_QUOTA_EXCEEDED` |
| `FireConflictError` | 409 — e.g. resuming a run that isn't actually paused |
| `FireNotFoundError` | 404 — unknown slug/run id, or a private flow you don't own |
| `FireServerError` | 5xx |
| `FireTimeoutError` | `wait_for_flow`/`FlowRun.wait()`'s own client-side poll timeout (not a Fire API error — subclasses the builtin `TimeoutError`) |

## Environments

```python
FireClient(token="...", base_url="https://fire.test1.prosaga.net")  # default
FireClient(token="...", base_url="https://fire.prosaga.net")        # prod
```

`base_url` is the bare host — `v1` and `v2` are sibling path prefixes on
Fire, not nested, and the SDK adds the right one per call.

## Why typed objects (and why L1/L2/v2 don't share a base class)

The original design considered a class hierarchy mirroring Fire's model
taxonomy end to end (a class per family/genus/species, `chat()` living on
the leaf class). We didn't build that — the taxonomy is live, server-
managed data (models get onboarded, deprecated, renamed continuously),
so a class hierarchy would either drift stale or demand constant releases
just to track it. `Species` (above) gets the discoverability without that
coupling: it's generated data, not inherited behavior.

What *did* seem worth doing: typed results instead of bare dicts, so
`result.content` beats `result["content"]` with real autocomplete and
typo safety. But L1 (`ChatResult`/`ImageResult`), L2 (`WorkflowResult`),
and v2 (`FlowRun`) are three separate root types, not one hierarchy —
they mirror three genuinely different layers Fire enforces server-side
(`FirePipeline` / `WorkflowContract` / `FlowEngine`), with different
contracts and, for `FlowRun`, genuinely different behavior (stateful and
pollable vs. one-shot). Forcing them under one base class would just be
inheritance for organization's sake, not because they share behavior.

## Not in scope yet

Streaming (`/v1/chat/stream`) isn't wrapped — this SDK is for LLM/script
use where a blocking call is usually what you want. Open an issue (or
just extend `fire_sdk/client.py`) if you need it.

## Development

```bash
python -m unittest discover -s tests -v   # mocked HTTP, no network, no deps beyond stdlib
```

Regenerate `fire_sdk/species.py` (and the PHP/JS equivalents) after a
model catalog change: `python3 ../scripts/generate_species.py` from this
directory, or `python3 scripts/generate_species.py` from the repo root.
