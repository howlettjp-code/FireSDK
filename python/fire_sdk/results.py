"""Typed result objects returned by FireClient.

Three separate root families, matching the three layers Fire itself
enforces server-side (FirePipeline / WorkflowContract / FlowEngine):

- L1 one-shot calls (ChatResult, ParallelChatResult, ImageResult) — frozen
  dataclasses, no behavior.
- L2 workflows (WorkflowResult) — deliberately thin. Each workflow slug
  (image, article, ...) defines its own response contract server-side;
  this does not attempt to model every field of every workflow. Use
  `.raw` for anything workflow-specific.
- v2 flows (FlowRun, FlowStep) — the one type that owns behavior, because
  a flow run is inherently not a one-shot result: `.wait()` polls,
  `.resume()` mutates server-side state.

Every object keeps the full original response as `.raw` — a field this
dataclass doesn't model yet (or never will, for something workflow- or
provider-specific) is always still reachable, so a server-side addition
never requires an SDK release just to stay usable.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import TYPE_CHECKING, Any

if TYPE_CHECKING:
    from .client import FireClient

# ─── L1 ──────────────────────────────────────────────────────────────────


@dataclass(frozen=True)
class Usage:
    input: int | None
    output: int | None


@dataclass(frozen=True)
class ChatResult:
    """POST /v1/chat — one chat completion."""

    content: str
    model: str
    provider: str
    usage: Usage
    price_usd: float | None
    log_id: int | None
    tool_calls: Any | None
    raw: dict[str, Any]

    @classmethod
    def _from_raw(cls, raw: dict[str, Any]) -> ChatResult:
        usage_raw = raw.get("usage") or {}
        meta = raw.get("meta") or {}
        return cls(
            content=raw.get("content", ""),
            model=raw.get("model", ""),
            provider=raw.get("provider", ""),
            usage=Usage(input=usage_raw.get("input"), output=usage_raw.get("output")),
            price_usd=(meta.get("price") or {}).get("usd"),
            log_id=meta.get("log_id", raw.get("log_id")),
            tool_calls=raw.get("tool_calls"),
            raw=raw,
        )


@dataclass(frozen=True)
class ChatItemResult:
    """One item inside a ParallelChatResult — can fail independently of its siblings."""

    ok: bool
    content: str | None
    model: str | None
    provider: str | None
    usage: Usage | None
    price_usd: float | None
    error: str | None
    raw: dict[str, Any]

    @classmethod
    def _from_raw(cls, raw: dict[str, Any]) -> ChatItemResult:
        usage_raw = raw.get("usage")
        meta = raw.get("meta") or {}
        return cls(
            ok=raw.get("ok", True),
            content=raw.get("content"),
            model=raw.get("model"),
            provider=raw.get("provider"),
            usage=Usage(input=usage_raw.get("input"), output=usage_raw.get("output")) if usage_raw else None,
            price_usd=(meta.get("price") or {}).get("usd"),
            error=raw.get("error"),
            raw=raw,
        )


@dataclass(frozen=True)
class ParallelChatResult:
    """POST /v1/chat/parallel — N independent completions, input order preserved."""

    results: list[ChatItemResult]
    raw: dict[str, Any]

    @classmethod
    def _from_raw(cls, raw: dict[str, Any]) -> ParallelChatResult:
        return cls(results=[ChatItemResult._from_raw(r) for r in raw.get("results", [])], raw=raw)


@dataclass(frozen=True)
class ImageFile:
    b64: str | None
    url: str | None


@dataclass(frozen=True)
class ImageResult:
    """POST /v1/image."""

    images: list[ImageFile]
    model: str
    provider: str
    price_usd: float | None
    log_id: int | None
    raw: dict[str, Any]

    @classmethod
    def _from_raw(cls, raw: dict[str, Any]) -> ImageResult:
        meta = raw.get("meta") or {}
        return cls(
            images=[ImageFile(b64=i.get("b64"), url=i.get("url")) for i in raw.get("images", [])],
            model=raw.get("model", ""),
            provider=raw.get("provider", ""),
            price_usd=(meta.get("price") or {}).get("usd"),
            log_id=meta.get("log_id"),
            raw=raw,
        )


# ─── L2 ──────────────────────────────────────────────────────────────────


@dataclass(frozen=True)
class WorkflowResult:
    """POST /v1/workflows/{slug}. Deliberately thin — see module docstring."""

    workflow: str
    raw: dict[str, Any]

    @classmethod
    def _from_raw(cls, workflow: str, raw: dict[str, Any]) -> WorkflowResult:
        return cls(workflow=workflow, raw=raw)


# ─── v2 ──────────────────────────────────────────────────────────────────

_FLOW_TERMINAL_STATUSES = {"completed", "failed", "cancelled", "awaiting_human"}


@dataclass(frozen=True)
class FlowStep:
    step_key: str
    kind: str
    status: str
    depends_on: list[str]
    error: str | None
    output: dict[str, Any] | None = None
    context: dict[str, Any] | None = None
    prompt_for_human: str | None = None
    human_input: dict[str, Any] | None = None
    raw: dict[str, Any] = field(default_factory=dict)

    @classmethod
    def _from_raw(cls, raw: dict[str, Any]) -> FlowStep:
        return cls(
            step_key=raw["step_key"],
            kind=raw["kind"],
            status=raw["status"],
            depends_on=raw.get("depends_on", []),
            error=raw.get("error"),
            output=raw.get("output"),
            context=raw.get("context"),
            prompt_for_human=raw.get("prompt_for_human"),
            human_input=raw.get("human_input"),
            raw=raw,
        )


class FlowRun:
    """v2 — a stateful, pollable flow run.

    Unlike every other result type in this module, FlowRun is not a frozen
    dataclass — it owns a reference back to the client so `.wait()` and
    `.resume()` read naturally as verbs on the run itself, e.g.:

        run = client.run_flow(flow_slug="triad", input={...})
        run.wait()
        if run.status == "awaiting_human":
            run.resume(run.gating_step().step_key, {"note": "..."})
            run.wait()
        print(run.output["content"])
    """

    def __init__(self, client: FireClient, raw: dict[str, Any]) -> None:
        self._client = client
        self._apply(raw)

    def _apply(self, raw: dict[str, Any]) -> None:
        self.raw = raw
        self.run_id: int = raw["run_id"]
        self.status: str = raw["status"]
        self.conversation_id = raw.get("conversation_id")
        self.input = raw.get("input")
        self.output = raw.get("output")
        self.error = raw.get("error")
        self.total_cost_usd = raw.get("total_cost_usd")
        self.started_at = raw.get("started_at")
        self.completed_at = raw.get("completed_at")
        self.steps: list[FlowStep] = [FlowStep._from_raw(s) for s in raw.get("steps", [])]

    def __repr__(self) -> str:  # pragma: no cover - cosmetic
        return f"FlowRun(run_id={self.run_id}, status={self.status!r})"

    @property
    def is_terminal(self) -> bool:
        return self.status in _FLOW_TERMINAL_STATUSES

    def gating_step(self) -> FlowStep | None:
        """The human_gate step currently pausing this run, if status == 'awaiting_human'."""
        return next((s for s in self.steps if s.status == "awaiting_human"), None)

    def refresh(self, *, verbosity: str = "full") -> FlowRun:
        self._apply(self._client.get_flow_run(self.run_id, verbosity=verbosity).raw)
        return self

    def wait(self, *, poll_interval: float = 1.5, timeout: float = 120.0, verbosity: str = "full") -> FlowRun:
        result = self._client.wait_for_flow(self.run_id, poll_interval=poll_interval, timeout=timeout, verbosity=verbosity)
        self._apply(result.raw)
        return self

    def resume(self, step_key: str, human_input: dict[str, Any], *, verbosity: str = "full") -> FlowRun:
        result = self._client.resume_flow_run(self.run_id, step_key, human_input, verbosity=verbosity)
        self._apply(result.raw)
        return self
