"""Fire SDK — a small Python client for the Fire AI Inference API.

    from fire_sdk import FireClient

    client = FireClient(token="fire_sk_...")
    result = client.chat([{"role": "user", "content": "Hello"}])
    print(result.content)

See README.md for the v2 multi-agent flows layer and the bundled
`triad` example.

1.0.0 (2026-08-03) is a breaking change from 0.x: result-bearing methods
(chat, chat_parallel, image, run_workflow, run_flow and friends) now
return typed objects (attribute access) instead of plain dicts (key
access) — see results.py. See CHANGELOG.md.
"""

from .client import FireClient
from .exceptions import (
    FireAuthError,
    FireBillingError,
    FireConflictError,
    FireError,
    FireNotFoundError,
    FireServerError,
    FireTimeoutError,
    FireValidationError,
)
from .results import (
    ChatItemResult,
    ChatResult,
    FlowRun,
    FlowStep,
    ImageFile,
    ImageResult,
    ParallelChatResult,
    Usage,
    WorkflowResult,
)
from .species import Species

__all__ = [
    "FireClient",
    "FireError",
    "FireAuthError",
    "FireBillingError",
    "FireValidationError",
    "FireConflictError",
    "FireNotFoundError",
    "FireServerError",
    "FireTimeoutError",
    "ChatResult",
    "ChatItemResult",
    "ParallelChatResult",
    "ImageResult",
    "ImageFile",
    "WorkflowResult",
    "FlowRun",
    "FlowStep",
    "Usage",
    "Species",
]

__version__ = "1.0.0"
