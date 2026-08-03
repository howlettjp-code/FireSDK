"""Exceptions raised by FireClient.

Every exception carries the parsed response body (``.response``, a dict)
alongside the HTTP status (``.status_code``) and Fire's own
``error_code``/``description`` when present, so a caller — human or LLM —
can branch on structured data instead of parsing a message string.
"""

from __future__ import annotations

from typing import Any


class FireError(Exception):
    """Base class for every error this SDK raises."""

    def __init__(
        self,
        message: str,
        *,
        status_code: int | None = None,
        error_code: str | None = None,
        response: dict[str, Any] | None = None,
    ) -> None:
        super().__init__(message)
        self.status_code = status_code
        self.error_code = error_code
        self.response = response or {}

    def __repr__(self) -> str:  # pragma: no cover - cosmetic
        return f"{type(self).__name__}(status_code={self.status_code}, error_code={self.error_code!r})"


class FireAuthError(FireError):
    """401 — missing, malformed, revoked, or under-scoped token."""


class FireBillingError(FireError):
    """402/500 — no billing tier resolvable, or account has no balance left."""


class FireValidationError(FireError):
    """422 — request failed validation. `.response` includes field errors
    (Laravel-style `errors`) or, for flow specs, a `details` list from
    FlowValidator."""


class FireConflictError(FireError):
    """409 — e.g. resuming a flow run that isn't awaiting human input, or
    with a step_key that doesn't match the step currently gating it."""


class FireNotFoundError(FireError):
    """404 — unknown flow/agent-config slug, run id, or model."""


class FireServerError(FireError):
    """5xx not covered above — provider/pipeline failure, etc."""


class FireTimeoutError(TimeoutError):
    """Raised by wait_for_flow()/FlowRun.wait() when a run is still
    pending/running after the given timeout — a client-side polling
    timeout, not a Fire API error, so it deliberately does not extend
    FireError. Subclasses the builtin TimeoutError (not FireError) so
    existing `except TimeoutError` handlers still catch it."""
