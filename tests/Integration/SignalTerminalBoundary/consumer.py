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


async def case(mode: str) -> None:
    CALLS.clear()
    entered, release = asyncio.Event(), asyncio.Event()
    receipts: list[dict[str, Any]] = []
    identity = f"signal-terminal-{mode}-{uuid4().hex}"
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
        handle = await client.start_workflow(
            workflow_type="qualification.signal-terminal-boundary", workflow_id=identity,
            task_queue=identity, input=[mode], execution_timeout_seconds=120, run_timeout_seconds=60,
        )
        worker = Worker(client, task_queue=identity, workflows=[BoundaryWorkflow], activities=[],
                        poll_timeout=0.2, shutdown_timeout=5)
        running = asyncio.create_task(worker.run_until(workflow_id=identity, timeout=20, poll_interval=0.1))
        try:
            await asyncio.wait_for(entered.wait(), timeout=10)
            # The original worker has read history and constructed its terminal command.
            await handle.signal("message", [{"integer": 9223372036854775807, "null": None, "text": "accepted 雪"}])
            release.set()
            await running
            expected = {"integer": 9223372036854775807, "null": None, "text": "accepted 雪"}
            print(json.dumps({"mode": mode, "workflow_id": identity, "initial_run_id": handle.run_id,
                              "callback_values": CALLS, "receipts": receipts}, ensure_ascii=False), flush=True)
            assert expected in CALLS, (mode, "acknowledged signal never reached workflow code")
            if mode == "complete":
                assert await handle.result(timeout=3) == [expected]
            history = await handle.get_history()
            applied = [event for event in history["events"] if event["event_type"] == "SignalApplied"]
            assert len(applied) == 1, (mode, "expected one durable signal application")
            runs = await handle.list_runs()
            assert runs.run_count == (2 if mode == "continue" else 1)
            print(json.dumps({"mode": mode, "namespace": client.namespace,
                              "workflow_id": identity, "initial_run_id": handle.run_id,
                              "run_count": runs.run_count, "callback_observed": True,
                              "signal_applied_count": len(applied), "receipts": receipts}, ensure_ascii=False), flush=True)
        finally:
            release.set()
            if not running.done():
                await worker.stop()
                await running


async def main() -> None:
    for mode in ("complete", "continue"):
        await case(mode)


asyncio.run(main())
