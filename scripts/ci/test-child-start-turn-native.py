#!/usr/bin/env python3
"""Run the embedded Core child-start scenarios against a disposable native Server."""
import argparse
import http.client
import json
import os
from pathlib import Path
import secrets
import subprocess
import time


FIXTURE = Path(__file__).resolve().parents[2] / "tests/Fixtures/V2/WorkerProtocol/child-start-turn.json"
IMAGE = "ghcr.io/durable-workflow/server:3.22.2@sha256:804bbf0fd87f0aaa7345adc3a7d73c3348f70e7263419b5cfc2d3faf7ba3a3d8"
EMPTY = {"codec": "avro", "blob": "wwHioz3/VYAiNwwA"}
SEVEN = {"codec": "avro", "blob": "wwHioz3/VYAiNwQO"}


def docker(*args, env=None):
    return subprocess.run(["docker", *args], check=True, capture_output=True, text=True, env=env).stdout.strip()


class NativeServer:
    def __init__(self, port, token, protocol):
        self.port = port
        self.headers = {
            "Authorization": "Bearer " + token,
            "Content-Type": "application/json",
            "X-Namespace": "default",
            "X-Durable-Workflow-Protocol-Version": protocol,
            "X-Durable-Workflow-Control-Plane-Version": "2",
        }

    def request(self, method, path, expected, body=None):
        connection = http.client.HTTPConnection("127.0.0.1", self.port, timeout=5)
        try:
            connection.request(method, path, json.dumps(body) if body is not None else None, self.headers)
            response = connection.getresponse()
            document = json.loads(response.read())
            assert response.status == expected, (path, response.status, document.get("message"), document.get("errors"))
            return document
        finally:
            connection.close()

    def wait(self):
        deadline = time.monotonic() + 30
        while True:
            try:
                return self.request("GET", "/api/cluster/info", 200)
            except OSError:
                if time.monotonic() >= deadline:
                    raise
                time.sleep(1)

    def register(self, worker, queue, workflow):
        return self.request("POST", "/api/worker/register", 201, {
            "worker_id": worker, "task_queue": queue, "runtime": "php",
            "supported_workflow_types": [workflow], "supported_activity_types": [],
            "capability_manifest": {
                name: {"supported": False, "minimum_protocol_version": "1.18"}
                for name in ("local_activities", "worker_sessions", "sticky_execution")
            },
        })

    def start(self, name, queue):
        return self.request("POST", "/api/workflows", 201, {
            "workflow_id": name, "workflow_type": "echo", "task_queue": queue, "input": EMPTY,
        })

    def poll(self, worker, queue):
        return self.request("POST", "/api/worker/workflow-tasks/poll", 200, {
            "worker_id": worker, "task_queue": queue, "timeout_seconds": 0,
        })["task"]

    def complete(self, task, commands):
        return self.request("POST", f"/api/worker/workflow-tasks/{task['task_id']}/complete", 200, {
            "lease_owner": task["lease_owner"],
            "workflow_task_attempt": task["workflow_task_attempt"],
            "commands": commands,
        })


def run_case(server, name, case):
    parent_queue = f"parent-{name}"
    child_queue = f"child-{name}"
    worker = f"initial-{name}"
    replacement = f"replacement-{name}"
    child_worker = f"child-worker-{name}"
    registration = server.register(worker, parent_queue, "echo")
    assert registration["server_capabilities"]["child_workflow_start_turn"] is True
    server.register(child_worker, child_queue, "child")

    child = {"type": "start_child_workflow", "workflow_type": "child", "queue": child_queue, "arguments": EMPTY}
    if "await" in case:
        child["await"] = case["await"]
    commands = [child] * case["children"]
    if case["close_parent"]:
        commands.append({"type": "complete_workflow", "result": SEVEN})

    parent = server.start(f"child-start-{name}", parent_queue)
    initial = server.poll(worker, parent_queue)
    assert initial["run_id"] == parent["run_id"]
    receipt = server.complete(initial, commands)
    assert len(receipt["created_task_ids"]) == case["children"] + case["parent_start_turns"]
    assert receipt["run_status"] == ("completed" if case["close_parent"] else "waiting")
    duplicate = server.complete(initial, commands)
    assert duplicate["recorded"] is False and duplicate["reason"] == "already_completed"
    assert duplicate["created_task_ids"] == receipt["created_task_ids"]

    server.register(replacement, parent_queue, "echo")
    turn = server.poll(replacement, parent_queue)
    assert (turn is not None) == bool(case["parent_start_turns"])
    if turn is None:
        if not case["close_parent"]:
            child_task = server.poll(child_worker, child_queue)
            server.complete(child_task, [{"type": "complete_workflow", "result": SEVEN}])
            result_turn = server.poll(replacement, parent_queue)
            assert result_turn["run_id"] == initial["run_id"]
            outcomes = [event["payload"] for event in result_turn["history_events"]
                        if event["event_type"] == "ChildRunCompleted"]
            assert len(outcomes) == 1 and outcomes[0]["child_workflow_run_id"] == child_task["run_id"]
            assert server.poll(child_worker, child_queue) is None
        return

    assert turn["run_id"] == initial["run_id"] and turn["task_id"] != initial["task_id"]
    child_events = [
        event for event in turn["history_events"]
        if event["event_type"] in ("ChildWorkflowScheduled", "ChildRunStarted", "ChildRunCompleted")
    ]
    assert [event["event_type"] for event in child_events] == [
        "ChildWorkflowScheduled", "ChildRunStarted",
    ] * case["children"]

    identities = []
    for scheduled, started in zip(child_events[::2], child_events[1::2], strict=True):
        call_id = started["payload"]["child_call_id"]
        run_id = started["payload"]["child_workflow_run_id"]
        assert scheduled["payload"]["child_call_id"] == call_id
        assert scheduled["payload"]["child_workflow_run_id"] == run_id
        identities.append((call_id, run_id))
    assert len(set(identities)) == case["children"]

    assert server.complete(turn, [{"type": "start_timer", "delay_seconds": 3600}])["run_status"] == "waiting"
    assert server.poll(replacement, parent_queue) is None

    first_child = server.poll(child_worker, child_queue)
    initial_child_run = first_child["run_id"]
    expected_call, = (call_id for call_id, run_id in identities if run_id == initial_child_run)
    continued = server.complete(first_child, [{"type": "continue_as_new"}])
    assert len(continued["created_task_ids"]) == 1
    assert server.poll(replacement, parent_queue) is None
    # A second child from the same initial parent completion may be ahead of
    # the continued run in the queue. Return results for each scheduled child.
    continued_run = None
    seen = {initial_child_run}
    for _ in range(case["children"]):
        task = server.poll(child_worker, child_queue)
        assert task["run_id"] not in seen
        seen.add(task["run_id"])
        if task["task_id"] == continued["created_task_ids"][0]:
            continued_run = task["run_id"]
        server.complete(task, [{"type": "complete_workflow", "result": SEVEN}])
    assert continued_run is not None
    assert server.poll(child_worker, child_queue) is None

    resumed = server.poll(replacement, parent_queue)
    assert resumed["run_id"] == initial["run_id"]
    completed_calls = {
        event["payload"]["child_call_id"] for event in resumed["history_events"]
        if event["event_type"] == "ChildRunCompleted"
    }
    starts = {
        (event["payload"]["child_call_id"], event["payload"]["child_workflow_run_id"])
        for event in resumed["history_events"] if event["event_type"] == "ChildRunStarted"
    }
    assert expected_call in completed_calls and (expected_call, initial_child_run) in starts


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--container-name", default="workflow-child-start-conformance")
    args = parser.parse_args()

    fixture = json.loads(FIXTURE.read_bytes())
    assert fixture["cases"]
    token = secrets.token_urlsafe(32)
    container = docker(
        "run", "-d", "--name", args.container_name, "--init", "--read-only",
        "--cpus", "1", "--memory", "512m", "--pids-limit", "128",
        "--cap-drop", "ALL", "--security-opt", "no-new-privileges",
        "--tmpfs", "/app/database:rw,size=64m,mode=0700,uid=1000,gid=1000",
        "--tmpfs", "/app/storage:rw,size=16m,mode=0700,uid=1000,gid=1000",
        "--tmpfs", "/tmp:rw,size=16m,mode=1777",
        "--publish", "127.0.0.1::8080", "--env", "DW_AUTH_TOKEN", IMAGE,
        env={**os.environ, "DW_AUTH_TOKEN": token},
    )
    try:
        address = docker("port", container, "8080/tcp")
        host, port = address.rsplit(":", 1)
        assert host == "127.0.0.1"
        server = NativeServer(int(port), token, fixture["protocol_version"])
        info = server.wait()
        assert info["version"] in ("3.22.2", "native-3.22.2"), info["version"]
        assert info["capabilities"]["child_workflow_start_turn"] is True
        for name, case in fixture["cases"].items():
            try:
                run_case(server, name, case)
            except (AssertionError, KeyError, TypeError, ValueError) as exception:
                raise AssertionError(f"{name}: {exception}") from exception
            print(f"{name}: PASS")
    finally:
        docker("rm", "--force", "--volumes", container)


if __name__ == "__main__":
    main()
