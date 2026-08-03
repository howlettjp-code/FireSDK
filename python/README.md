# FireSDK (Python)

A small Python client for the [Fire AI Inference API](https://fire.test1.prosaga.net/v1/capabilities)
— model-agnostic chat completions plus a data-driven, resumable
multi-agent flow layer. Built for LLM-agent and test-script use right
now: synchronous, minimal dependencies (just `requests`), every method
returns a plain `dict` (the parsed JSON response) rather than a
custom object model.

```bash
pip install -e .          # from a clone of this repo
```

```python
from fire_sdk import FireClient

client = FireClient(token="fire_sk_...")
result = client.chat([{"role": "user", "content": "Hello"}])
print(result["content"])
```

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
caps = client.capabilities()   # no token required
print(caps["planes"].keys())   # ('data', 'workflows', 'service_control', 'flows', 'billing', 'governance')
print(caps["models"])          # every active model + its `strengths`
```

## Chat

```python
client.chat(
    [{"role": "user", "content": "What is 2+2?"}],
    system_prompt="Be concise.",
    species_name="claude-sonnet-4-5",   # omit for the default model
    temperature=0.7,
)

client.chat_parallel([
    {"messages": [{"role": "user", "content": "..."}], "species_name": "gpt-4o"},
    {"messages": [{"role": "user", "content": "..."}], "species_name": "claude-sonnet-4-5"},
])
```

Note: models tagged `"reasoning"` in their `strengths` (check
`client.models()`) spend part of their `max_tokens` budget on hidden
reasoning before any visible output — Fire silently raises a too-low
`max_tokens` to a safe floor server-side so you never get billed for an
empty response, but if you're passing a very tight budget on purpose,
know that it may come back larger than you asked for.

## v2 — multi-agent flows

The interesting part. A **flow** is a DAG of steps — `agent_call` (a
model call using a named, reusable "agent config": model + temperature +
system prompt) and `human_gate` (pauses the run for a person to weigh
in). Flows run **asynchronously** — starting or resuming one returns
immediately with `status: "pending"`/`"running"`, and you poll (or use
`wait_for_flow`) until it lands on `completed`, `failed`, or
`awaiting_human`.

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
run = client.wait_for_flow(run["run_id"])

if run["status"] == "awaiting_human":
    gate = next(s for s in run["steps"] if s["status"] == "awaiting_human")
    print(gate["prompt_for_human"])
    print("Agreeable:", gate["context"]["hot_1"])
    print("Contrarian:", gate["context"]["hot_2"])

    run = client.resume_flow_run(run["run_id"], gate["step_key"], {"note": "your guidance"})
    run = client.wait_for_flow(run["run_id"])

print(run["output"]["content"])       # the cool synthesizer's final answer
print(run["total_cost_usd"])          # real, billed cost across all 3 agent calls
```

### Building your own flow

Reusable pieces first:

```python
client.create_agent_config(
    "my-hot-agent", "My Hot Agent",
    species_name="claude-sonnet-4-5", system_prompt="...", temperature=0.9,
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

`{{token.path}}` inside a step's message content resolves against
`input.*` and any already-completed step's `{{step_key.output.content}}`
/ `{{step_key.human_input.*}}`. See `examples/triad_example.py` and
`fire_sdk/client.py`'s docstrings for the full shape — or just inspect
`client.get_flow_definition("triad")` to see a real one.

### Saving bandwidth while polling

Pass `verbosity="compact"` to `run_flow`, `get_flow_run`, or
`resume_flow_run` if you only care about the final answer and don't want
every step's full content in every poll response:

```python
run = client.get_flow_run(run_id, verbosity="compact")
# steps only carry step_key/kind/status/depends_on/error — no output/context
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
| `FireValidationError` | 422 — bad request, or an invalid flow spec (`.response["details"]`) |
| `FireConflictError` | 409 — e.g. resuming a run that isn't actually paused |
| `FireNotFoundError` | 404 — unknown slug/run id |
| `FireServerError` | 5xx |

## Environments

```python
FireClient(token="...", base_url="https://fire.test1.prosaga.net")  # default
FireClient(token="...", base_url="https://fire.prosaga.net")        # prod
```

`base_url` is the bare host — `v1` and `v2` are sibling path prefixes on
Fire, not nested, and the SDK adds the right one per call.

## Not in scope yet

Streaming (`/v1/chat/stream`) isn't wrapped — this SDK is for LLM/script
use where a blocking call is usually what you want. Open an issue (or
just extend `fire_sdk/client.py`) if you need it.

## Development

```bash
python -m unittest discover -s tests -v   # mocked HTTP, no network, no deps beyond stdlib
```
