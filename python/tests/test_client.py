"""Unit tests for FireClient — mocked HTTP only, no network. Run with:

    python -m unittest discover -s tests

(or pytest, which also runs unittest.TestCase classes fine, if installed).
"""

from __future__ import annotations

import unittest
from unittest.mock import MagicMock, patch

from fire_sdk import (
    FireAuthError,
    FireClient,
    FireConflictError,
    FireNotFoundError,
    FireValidationError,
)


def _mock_response(status_code: int, json_body: dict, ok: bool | None = None) -> MagicMock:
    resp = MagicMock()
    resp.status_code = status_code
    resp.ok = ok if ok is not None else 200 <= status_code < 300
    resp.content = b"1"  # truthy, so .json() is attempted
    resp.json.return_value = json_body
    return resp


class FireClientRoutingTest(unittest.TestCase):
    """v1 and v2 are sibling path prefixes, not nested — every method must
    hit the right one. (Regression: an early draft defaulted base_url to
    .../v1 and built v2 paths under it, breaking every flow call.)"""

    def setUp(self) -> None:
        self.client = FireClient(token="fire_sk_test", base_url="https://fire.example.test")

    def _patched(self, status_code: int, body: dict):
        return patch.object(
            self.client._session, "request", return_value=_mock_response(status_code, body)
        )

    def test_chat_hits_v1(self):
        with self._patched(200, {"content": "hi"}) as mock_request:
            self.client.chat([{"role": "user", "content": "hi"}])
        url = mock_request.call_args.args[1]
        self.assertEqual(url, "https://fire.example.test/v1/chat")

    def test_chat_parallel_hits_v1(self):
        with self._patched(200, {"results": []}) as mock_request:
            self.client.chat_parallel([{"messages": [{"role": "user", "content": "hi"}]}])
        url = mock_request.call_args.args[1]
        self.assertEqual(url, "https://fire.example.test/v1/chat/parallel")

    def test_run_flow_with_slug_hits_slug_endpoint(self):
        with self._patched(202, {"run_id": 1, "status": "pending"}) as mock_request:
            self.client.run_flow(flow_slug="triad", input={"prompt_package": "x"})
        url = mock_request.call_args.args[1]
        self.assertEqual(url, "https://fire.example.test/v2/flows/triad/run")

    def test_run_flow_with_inline_spec_hits_deprecated_body_endpoint(self):
        with self._patched(202, {"run_id": 1, "status": "pending"}) as mock_request:
            self.client.run_flow(flow={"steps": []}, input={})
        url = mock_request.call_args.args[1]
        self.assertEqual(url, "https://fire.example.test/v2/flows/run")

    def test_get_flow_run_hits_v2(self):
        with self._patched(200, {"run_id": 1, "status": "completed"}) as mock_request:
            self.client.get_flow_run(1)
        url = mock_request.call_args.args[1]
        self.assertEqual(url, "https://fire.example.test/v2/flows/runs/1")

    def test_resume_flow_run_hits_v2(self):
        with self._patched(202, {"run_id": 1, "status": "running"}) as mock_request:
            self.client.resume_flow_run(1, "review", {"note": "x"})
        url = mock_request.call_args.args[1]
        self.assertEqual(url, "https://fire.example.test/v2/flows/runs/1/resume")

    def test_agent_config_crud_hits_v2(self):
        with self._patched(201, {"slug": "x"}) as mock_request:
            self.client.create_agent_config("x", "X", species_name="m")
        self.assertEqual(mock_request.call_args.args[1], "https://fire.example.test/v2/agent-configs")

        with self._patched(200, {"slug": "x"}) as mock_request:
            self.client.get_agent_config("x")
        self.assertEqual(mock_request.call_args.args[1], "https://fire.example.test/v2/agent-configs/x")

    def test_capabilities_needs_no_token(self):
        anon = FireClient(base_url="https://fire.example.test")
        with patch.object(anon._session, "request", return_value=_mock_response(200, {"planes": {}})) as mock_request:
            anon.capabilities()
        headers = mock_request.call_args.kwargs["headers"]
        self.assertNotIn("Authorization", headers)


class FireClientErrorMappingTest(unittest.TestCase):
    def setUp(self) -> None:
        self.client = FireClient(token="fire_sk_test", base_url="https://fire.example.test")

    def test_401_raises_auth_error(self):
        with patch.object(
            self.client._session, "request",
            return_value=_mock_response(401, {"error_code": "AUTH_FAILURE", "description": "nope"}),
        ):
            with self.assertRaises(FireAuthError) as ctx:
                self.client.status()
        self.assertEqual(ctx.exception.error_code, "AUTH_FAILURE")

    def test_404_raises_not_found(self):
        with patch.object(
            self.client._session, "request",
            return_value=_mock_response(404, {"message": "No query results"}),
        ):
            with self.assertRaises(FireNotFoundError):
                self.client.get_flow_definition("ghost")

    def test_409_raises_conflict(self):
        with patch.object(
            self.client._session, "request",
            return_value=_mock_response(409, {"error_code": "FLOW_RESUME_CONFLICT", "description": "not waiting"}),
        ):
            with self.assertRaises(FireConflictError):
                self.client.resume_flow_run(1, "review", {})

    def test_422_raises_validation_error_with_details(self):
        body = {"error_code": "FLOW_INVALID", "description": "bad", "details": ["step 'a': ..."]}
        with patch.object(self.client._session, "request", return_value=_mock_response(422, body)):
            with self.assertRaises(FireValidationError) as ctx:
                self.client.create_flow_definition("x", "X", {"steps": []})
        self.assertEqual(ctx.exception.response["details"], ["step 'a': ..."])


class FireClientVerbosityTest(unittest.TestCase):
    def setUp(self) -> None:
        self.client = FireClient(token="fire_sk_test", base_url="https://fire.example.test")

    def test_default_verbosity_sends_no_query_param(self):
        with patch.object(
            self.client._session, "request", return_value=_mock_response(200, {"run_id": 1, "status": "completed"})
        ) as mock_request:
            self.client.get_flow_run(1)
        self.assertIsNone(mock_request.call_args.kwargs["params"])

    def test_compact_verbosity_sends_query_param(self):
        with patch.object(
            self.client._session, "request", return_value=_mock_response(200, {"run_id": 1, "status": "completed"})
        ) as mock_request:
            self.client.get_flow_run(1, verbosity="compact")
        self.assertEqual(mock_request.call_args.kwargs["params"], {"verbosity": "compact"})


class WaitForFlowTest(unittest.TestCase):
    def setUp(self) -> None:
        self.client = FireClient(token="fire_sk_test", base_url="https://fire.example.test")

    def test_stops_on_terminal_status(self):
        with patch.object(self.client, "get_flow_run", return_value={"run_id": 1, "status": "completed"}):
            result = self.client.wait_for_flow(1, poll_interval=0)
        self.assertEqual(result["status"], "completed")

    def test_stops_on_awaiting_human(self):
        with patch.object(self.client, "get_flow_run", return_value={"run_id": 1, "status": "awaiting_human"}):
            result = self.client.wait_for_flow(1, poll_interval=0)
        self.assertEqual(result["status"], "awaiting_human")

    def test_times_out_while_still_running(self):
        with patch.object(self.client, "get_flow_run", return_value={"run_id": 1, "status": "running"}):
            with self.assertRaises(TimeoutError):
                self.client.wait_for_flow(1, poll_interval=0, timeout=0)


class RedeemTierCodeTest(unittest.TestCase):
    def test_returns_token_and_ready_client(self):
        body = {"token": "fire_sk_new", "account_id": 1, "markup_pct": 20, "tier": "infra"}
        with patch(
            "fire_sdk.client.requests.Session.request", return_value=_mock_response(200, body)
        ):
            token, response, client = FireClient.redeem_tier_code(
                "CODE1", "Tester", "tester@example.test", base_url="https://fire.example.test"
            )
        self.assertEqual(token, "fire_sk_new")
        self.assertEqual(response["tier"], "infra")
        self.assertEqual(client.token, "fire_sk_new")


if __name__ == "__main__":
    unittest.main()
