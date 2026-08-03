#!/usr/bin/env python3
"""Runs Fire's bundled "triad" flow: two hot agents (one agreeable, one
contrarian) answer your question in parallel, you review both, then a
cool synthesizer writes the final answer.

Usage:
    FIRE_TOKEN=fire_sk_... python3 examples/triad_example.py "Should cities ban cars downtown?"

Get a token from whoever gave you access to this SDK, or self-serve one
with a tier code (see README.md's "Getting a token" section).
"""

from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

from fire_sdk import FireClient  # noqa: E402


def main() -> None:
    token = os.environ.get("FIRE_TOKEN")
    if not token:
        raise SystemExit("Set FIRE_TOKEN in your environment first.")

    question = " ".join(sys.argv[1:]) or "Should remote work be the default for knowledge workers?"

    client = FireClient(token=token)

    print(f"Question: {question}\n")
    print("Starting the triad flow (hot_1 + hot_2 run in parallel)...")
    run = client.run_flow(flow_slug="triad", input={"prompt_package": question})
    print(f"  run_id={run.run_id} status={run.status}")

    # Execution is always queued — poll until it lands somewhere interesting.
    # FlowRun.wait() mutates the run object in place, so `run` stays current.
    run.wait()
    print(f"  -> {run.status}\n")

    if run.status == "awaiting_human":
        gate = run.gating_step()

        print(f"Paused for human review: {gate.prompt_for_human}\n")
        print("Agreeable take:")
        print(f"  {gate.context['hot_1']}\n")
        print("Contrarian take:")
        print(f"  {gate.context['hot_2']}\n")

        note = input("Guidance for the synthesizer (or press Enter to let it decide): ")

        run.resume(gate.step_key, {"note": note})
        run.wait()
        print(f"\n  -> {run.status}")

    if run.status == "completed":
        print("\n=== Final synthesized answer ===")
        print(run.output["content"])
        print(f"\n(cost: ${run.total_cost_usd:.6f})")
    else:
        print(f"\nRun ended in status={run.status!r}: {run.error}")


if __name__ == "__main__":
    main()
