"""FireClient — synchronous client for the Fire AI Inference API.

Covers L1 (raw chat calls) and v2 (data-driven, resumable multi-agent
flows). Every method returns the parsed JSON response as a plain dict —
deliberately no custom response objects, since the callers this SDK
targets right now (LLM agents, test scripts) work naturally with JSON and
don't benefit from having to learn an SDK-specific object model on top of
it.
"""

from __future__ import annotations

import time
from typing import Any, Iterable

import requests

from .exceptions import (
    FireAuthError,
    FireBillingError,
    FireConflictError,
    FireError,
    FireNotFoundError,
    FireServerError,
    FireValidationError,
)

DEFAULT_BASE_URL = "https://fire.test1.prosaga.net"

# Terminal flow-run statuses — wait_for_flow() stops polling on any of these.
_FLOW_TERMINAL_STATUSES = {"completed", "failed", "cancelled", "awaiting_human"}


class FireClient:
    """A Fire API client bound to one token.

    Args:
        token: A ``fire_sk_...`` bearer token. Omit only if you intend to
            call :meth:`capabilities` or :meth:`redeem_tier_code`, the two
            endpoints that don't require one.
        base_url: Root URL, no version suffix — v1 and v2 are sibling
            path prefixes, not nested (``.../v1/chat``, ``.../v2/flows/run``
            on the same host). Defaults to Fire's test1 environment; pass
            ``https://fire.prosaga.net`` once your token/flows are
            promoted to prod.
        timeout: Per-request timeout in seconds.
    """

    def __init__(
        self,
        token: str | None = None,
        base_url: str = DEFAULT_BASE_URL,
        timeout: float = 60.0,
    ) -> None:
        self.token = token
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self._session = requests.Session()

    # ── internals ───────────────────────────────────────────────────────

    def _headers(self) -> dict[str, str]:
        headers = {"Accept": "application/json", "Content-Type": "application/json"}
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        return headers

    def _request(self, method: str, path: str, **kwargs: Any) -> dict[str, Any]:
        url = f"{self.base_url}/{path.lstrip('/')}"
        response = self._session.request(
            method, url, headers=self._headers(), timeout=self.timeout, **kwargs
        )

        try:
            body = response.json() if response.content else {}
        except ValueError:
            body = {"raw": response.text}

        if response.ok:
            return body

        self._raise_for_status(response.status_code, body)

    @staticmethod
    def _raise_for_status(status_code: int, body: dict[str, Any]) -> None:
        error_code = body.get("error_code")
        message = body.get("description") or body.get("error") or body.get("message") or str(body)

        kwargs = {"status_code": status_code, "error_code": error_code, "response": body}

        if status_code == 401:
            raise FireAuthError(message, **kwargs)
        if status_code == 402 or error_code in ("PRICING_TIER_UNAVAILABLE", "INSUFFICIENT_BALANCE"):
            raise FireBillingError(message, **kwargs)
        if status_code == 404:
            raise FireNotFoundError(message, **kwargs)
        if status_code == 409:
            raise FireConflictError(message, **kwargs)
        if status_code == 422:
            raise FireValidationError(message, **kwargs)
        if status_code >= 500:
            raise FireServerError(message, **kwargs)
        raise FireError(message, **kwargs)

    # ── service discovery / diagnostics ─────────────────────────────────

    def capabilities(self) -> dict[str, Any]:
        """GET /capabilities — no auth required. Start here to confirm
        which planes (data/flows/billing/...) and models are live before
        assuming anything about the API surface."""
        return self._request("GET", "v1/capabilities")

    def status(self) -> dict[str, Any]:
        return self._request("GET", "v1/status")

    def models(self) -> dict[str, Any]:
        """Every active, onboarded model deployment, including each
        species' `strengths` (e.g. "reasoning") and `capabilities`."""
        return self._request("GET", "v1/models")

    def usage(self) -> dict[str, Any]:
        return self._request("GET", "v1/usage")

    # ── L1 — chat ────────────────────────────────────────────────────────

    def chat(
        self,
        messages: list[dict[str, str]],
        *,
        system_prompt: str | None = None,
        species_name: str | None = None,
        temperature: float = 0.7,
        max_tokens: int = 1024,
        tags: list[str] | None = None,
        options: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        """POST /chat — a single chat completion.

        Note: for models tagged "reasoning" (see `strengths` in
        :meth:`models`), Fire silently raises a too-low ``max_tokens`` to
        a safe floor server-side — these models spend part of the budget
        on hidden reasoning before any visible output.
        """
        body: dict[str, Any] = {"messages": messages, "temperature": temperature, "max_tokens": max_tokens}
        if system_prompt is not None:
            body["system_prompt"] = system_prompt
        if species_name is not None:
            body["species_name"] = species_name
        if tags is not None:
            body["tags"] = tags
        if options is not None:
            body["options"] = options

        return self._request("POST", "v1/chat", json=body)

    def chat_parallel(self, requests_: Iterable[dict[str, Any]]) -> dict[str, Any]:
        """POST /chat/parallel — up to 10 independent chat requests run
        concurrently, each item accepting the same fields as :meth:`chat`.
        No synthesis step — use a v2 flow if you need one call to see the
        others' outputs."""
        return self._request("POST", "v1/chat/parallel", json={"requests": list(requests_)})

    # ── v2 — agent configs (reusable model+temperature+system_prompt) ──

    def create_agent_config(
        self,
        slug: str,
        label: str,
        *,
        species_name: str | None = None,
        system_prompt: str | None = None,
        temperature: float | None = None,
        max_tokens: int | None = None,
        role_tag: str | None = None,
        options: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        body = {"slug": slug, "label": label}
        for key, value in {
            "species_name": species_name,
            "system_prompt": system_prompt,
            "temperature": temperature,
            "max_tokens": max_tokens,
            "role_tag": role_tag,
            "options": options,
        }.items():
            if value is not None:
                body[key] = value
        return self._request("POST", "v2/agent-configs", json=body)

    def get_agent_config(self, slug: str) -> dict[str, Any]:
        return self._request("GET", f"v2/agent-configs/{slug}")

    def list_agent_configs(self) -> dict[str, Any]:
        return self._request("GET", "v2/agent-configs")

    def update_agent_config(self, slug: str, **fields: Any) -> dict[str, Any]:
        return self._request("PUT", f"v2/agent-configs/{slug}", json=fields)

    def delete_agent_config(self, slug: str) -> dict[str, Any]:
        return self._request("DELETE", f"v2/agent-configs/{slug}")

    # ── v2 — flow definitions (reusable named DAG templates) ────────────

    def create_flow_definition(
        self, slug: str, label: str, spec: dict[str, Any], *, description: str | None = None
    ) -> dict[str, Any]:
        body: dict[str, Any] = {"slug": slug, "label": label, "spec": spec}
        if description is not None:
            body["description"] = description
        return self._request("POST", "v2/flows", json=body)

    def get_flow_definition(self, slug: str) -> dict[str, Any]:
        return self._request("GET", f"v2/flows/{slug}")

    def list_flow_definitions(self) -> dict[str, Any]:
        return self._request("GET", "v2/flows")

    def update_flow_definition(self, slug: str, **fields: Any) -> dict[str, Any]:
        return self._request("PUT", f"v2/flows/{slug}", json=fields)

    def delete_flow_definition(self, slug: str) -> dict[str, Any]:
        return self._request("DELETE", f"v2/flows/{slug}")

    # ── v2 — flow runs ───────────────────────────────────────────────────

    def run_flow(
        self,
        *,
        flow_slug: str | None = None,
        flow: dict[str, Any] | None = None,
        input: dict[str, Any] | None = None,
        conversation_id: int | None = None,
        user_message: str | None = None,
        verbosity: str = "full",
    ) -> dict[str, Any]:
        """Start a flow run and return immediately (execution is always
        queued; use :meth:`wait_for_flow` or poll :meth:`get_flow_run`
        yourself for the result).

        Pass exactly one of ``flow_slug`` (a saved flow, e.g. ``"triad"``)
        or ``flow`` (a full inline ``{"steps": [...]}`` spec).

        ``flow_slug`` calls ``POST /v2/flows/{slug}/run`` (added
        2026-08-03) — the slug itself is the endpoint, same shape as L1's
        ``POST /v1/workflows/{workflow}``. ``flow`` (an unsaved/ad-hoc
        spec) has no slug to route on and still calls
        ``POST /v2/flows/run``, the only path that accepts an inline spec.
        """
        if not flow_slug and not flow:
            raise ValueError("run_flow() requires flow_slug or flow")

        body: dict[str, Any] = {"input": input or {}}
        if conversation_id is not None:
            body["conversation_id"] = conversation_id
        if user_message is not None:
            body["user_message"] = user_message

        params = {"verbosity": verbosity} if verbosity != "full" else None

        if flow_slug:
            return self._request("POST", f"v2/flows/{flow_slug}/run", json=body, params=params)

        body["flow"] = flow
        return self._request("POST", "v2/flows/run", json=body, params=params)

    def get_flow_run(self, run_id: int, *, verbosity: str = "full") -> dict[str, Any]:
        params = {"verbosity": verbosity} if verbosity != "full" else None
        return self._request("GET", f"v2/flows/runs/{run_id}", params=params)

    def list_flow_runs(
        self,
        *,
        conversation_id: int | None = None,
        status: str | None = None,
        per_page: int = 25,
    ) -> dict[str, Any]:
        params: dict[str, Any] = {"per_page": per_page}
        if conversation_id is not None:
            params["conversation_id"] = conversation_id
        if status is not None:
            params["status"] = status
        return self._request("GET", "v2/flows/runs", params=params)

    def resume_flow_run(
        self, run_id: int, step_key: str, human_input: dict[str, Any], *, verbosity: str = "full"
    ) -> dict[str, Any]:
        """POST /flows/runs/{run}/resume — supply human input for the step
        currently gating a paused run (``status == "awaiting_human"``).
        Raises :class:`~fire_sdk.FireConflictError` if the run isn't
        actually waiting, or ``step_key`` doesn't match the current gate.
        """
        params = {"verbosity": verbosity} if verbosity != "full" else None
        body = {"step_key": step_key, "human_input": human_input}
        return self._request("POST", f"v2/flows/runs/{run_id}/resume", json=body, params=params)

    def wait_for_flow(
        self,
        run_id: int,
        *,
        poll_interval: float = 1.5,
        timeout: float = 120.0,
        verbosity: str = "full",
    ) -> dict[str, Any]:
        """Poll :meth:`get_flow_run` until it lands on a terminal status —
        ``completed``, ``failed``, ``cancelled``, or ``awaiting_human``
        (a human gate is a legitimate stopping point, not a failure; check
        ``result["status"]`` and, if ``"awaiting_human"``, call
        :meth:`resume_flow_run` with the gating step's ``step_key``).

        Raises :class:`TimeoutError` if the run is still ``pending``/
        ``running`` after ``timeout`` seconds.
        """
        deadline = time.monotonic() + timeout
        while True:
            run = self.get_flow_run(run_id, verbosity=verbosity)
            if run["status"] in _FLOW_TERMINAL_STATUSES:
                return run
            if time.monotonic() >= deadline:
                raise TimeoutError(f"flow run {run_id} still '{run['status']}' after {timeout}s")
            time.sleep(poll_interval)

    # ── v2 — self-service tier onboarding ───────────────────────────────

    @classmethod
    def redeem_tier_code(
        cls,
        code: str,
        name: str,
        email: str,
        *,
        base_url: str = DEFAULT_BASE_URL,
        timeout: float = 60.0,
    ) -> tuple[str, dict[str, Any], "FireClient"]:
        """POST /billing/tier/redeem — trade a JP-issued code for a real
        token, no existing token required. Returns
        ``(raw_token, response_body, client)`` where ``client`` is a ready
        :class:`FireClient` already carrying the new token — the common
        case is just ``_, _, client = FireClient.redeem_tier_code(...)``.
        """
        anon = cls(token=None, base_url=base_url, timeout=timeout)
        body = anon._request(
            "POST", "v1/billing/tier/redeem", json={"code": code, "name": name, "email": email}
        )
        token = body["token"]
        return token, body, cls(token=token, base_url=base_url, timeout=timeout)
