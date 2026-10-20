"""Real installed-worker barrier; never replaces an HTTP response."""
from __future__ import annotations

import asyncio
import json
import os
from typing import Any
from uuid import uuid4

from durable_workflow import Client, Worker, workflow

CALLS: list[Any] = []


@workflow.defn(name="qualification.signal-terminal-boundary")
class BoundaryWorkflow:
    def __init__(self) -> None:
        self.seen: list[Any] = []

    @workflow.signal("message")
    def on_message(self, value: Any) -> None:
        self.seen.append(value)
        CALLS.append(value)

    def run(self, ctx: Any, mode: str, generation: int = 0) -> Any:
        yield from ()
        if mode == "continue" and generation == 0:
            return ctx.continue_as_new(mode, 1)
        return self.seen


async def case(mode: str, contract: str) -> None:
    CALLS.clear()
    entered, release = asyncio.Event(), asyncio.Event()
    receipts: list[dict[str, Any]] = []
    identity = f"signal-terminal-{mode}-{contract}-{uuid4().hex}"
    url, token = os.environ["SERVER_URL"], os.environ["AUTH_TOKEN"]

    async def hold_first_terminal(request: Any) -> None:
        if request.url.path.endswith("/complete") and not entered.is_set():
            body = json.loads(request.content)
            if any(c["type"] in {"complete_workflow", "continue_as_new"} for c in body.get("commands", [])):
                entered.set()
                await release.wait()

    async def capture(response: Any) -> None:
        if "/signal/" in response.request.url.path or response.request.url.path.endswith("/complete"):
            await response.aread()
            body = response.json()
            receipts.append({"path": response.request.url.path, "status": response.status_code,
                             "outcome": body.get("outcome"), "recorded": body.get("recorded"),
                             "run_status": body.get("run_status"), "reason": body.get("reason")})

    async with asyncio.timeout(30), Client(url, token=token, namespace=os.environ.get("NAMESPACE", "default")) as client:
        client._http.event_hooks = {"request": [hold_first_terminal], "response": [capture]}
        worker = Worker(client, task_queue=identity, workflows=[BoundaryWorkflow], activities=[],
                        poll_timeout=0.2, shutdown_timeout=5)
        running = None
        if contract == "declared":
            running = asyncio.create_task(worker.run())
            await asyncio.wait_for(worker._registration_done.wait(), timeout=10)
            if running.done():
                await running
        handle = await client.start_workflow(
            workflow_type="qualification.signal-terminal-boundary", workflow_id=identity,
            task_queue=identity, input=[mode], execution_timeout_seconds=120, run_timeout_seconds=60,
        )
        if running is None:
            running = asyncio.create_task(worker.run())
        try:
            await asyncio.wait_for(entered.wait(), timeout=10)
            # The original worker has read history and constructed its terminal command.
            signal_name = "not-declared" if contract == "legacy-unknown" else "message"
            await handle.signal(signal_name, [{"integer": 9223372036854775807, "null": None, "text": "accepted 雪"}])
            release.set()
            async with asyncio.timeout(20):
                while (await handle.describe()).status != "completed":
                    if running.done():
                        await running
                    await asyncio.sleep(0.1)
            expected = {"integer": 9223372036854775807, "null": None, "text": "accepted 雪"}
            print(json.dumps({"mode": mode, "contract": contract, "workflow_id": identity, "initial_run_id": handle.run_id,
                              "callback_values": CALLS, "receipts": receipts}, ensure_ascii=False), flush=True)
            assert CALLS == ([expected] if contract == "declared" else []), (mode, contract, CALLS)
            if mode == "complete":
                assert await handle.result(timeout=3) == ([expected] if contract == "declared" else [])
            runs = await handle.list_runs()
            assert runs.run_count == (2 if mode == "continue" else 1)
            events = []
            for run in runs.runs:
                events.extend((await client.get_history(identity, run.run_id))["events"])
            applied = [event for event in events if event["event_type"] == "SignalApplied"]
            rejected = [event for event in events if event["event_type"] == "SignalRejected"]
            assert len(applied) == (1 if contract == "declared" else 0)
            assert len(rejected) == (0 if contract == "declared" else 1)
            if rejected:
                assert rejected[0]["payload"]["rejection_reason"] == "run_closed_before_signal_applied"
            print(json.dumps({"mode": mode, "contract": contract, "namespace": client.namespace,
                              "workflow_id": identity, "initial_run_id": handle.run_id,
                              "run_count": runs.run_count, "callback_observed": bool(CALLS),
                              "signal_applied_count": len(applied), "signal_rejected_count": len(rejected),
                              "receipts": receipts}, ensure_ascii=False), flush=True)
        finally:
            release.set()
            await worker.stop()
            await running


async def main() -> None:
    for mode in ("complete", "continue"):
        for contract in ("declared", "legacy-known", "legacy-unknown"):
            await case(mode, contract)


asyncio.run(main())
