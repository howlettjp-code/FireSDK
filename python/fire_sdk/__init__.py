"""Fire SDK — a small Python client for the Fire AI Inference API.

    from fire_sdk import FireClient

    client = FireClient(token="fire_sk_...")
    result = client.chat([{"role": "user", "content": "Hello"}])
    print(result["content"])

See README.md for the v2 multi-agent flows layer and the bundled
`triad` example.
"""

from .client import FireClient
from .exceptions import (
    FireAuthError,
    FireBillingError,
    FireConflictError,
    FireError,
    FireNotFoundError,
    FireServerError,
    FireValidationError,
)

__all__ = [
    "FireClient",
    "FireError",
    "FireAuthError",
    "FireBillingError",
    "FireValidationError",
    "FireConflictError",
    "FireNotFoundError",
    "FireServerError",
]

__version__ = "0.3.0"
