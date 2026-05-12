# Sagas Conformance Experiment

## Goal

Prove that Durable Workflow saga semantics hold end to end against
published artifacts, not checked-out source:

- a successful saga executes only forward actions;
- a mid-sequence failure compensates completed forward actions in reverse order;
- recovery after partial compensation progress does not double-compensate or skip;
- PHP and Python saga workflows produce the same externally visible result.

This experiment is intentionally black-box. It runs the latest published
server image and latest published PHP and Python SDK packages resolved at
run time, then records the exact pins used for the run.

## Verify-First Notes

Read-only source consultation for the current implementation:

- `workflow` v2 implements PHP saga authoring with
  `Workflow\V2\Workflow::addCompensation()` and `compensate()`. The feature
  tests cover reverse-order compensation, no compensation on success,
  compensation history events, and continue-with-error compensation modes.
- `sdk-python` main has the lower-level workflow commands needed to model the
  same saga shape. It throws `ChildWorkflowFailed` into workflow code when a
  child workflow fails, which is sufficient for the cross-language experiment
  below. It does not yet expose `ActivityFailed` as a catchable Python workflow
  exception; that activity-only saga gap is tracked separately as tech debt.
- `server` main records normal activity, child-workflow, timer, and workflow
  history events. There is no hidden server-side saga log; the oracle below
  inspects externally visible workflow results and history.

## Install

Prerequisites on the machine running the experiment:

- Docker with Compose v2;
- Python 3.10 or newer with `venv`;
- an operator checkout with the pipeline CLI available at
  `$WORKSPACE_HQ/bin/pipeline`.

The experiment must not install from any `repos/*` checkout and must not
modify any `repos/*` checkout. Use a temporary run directory under the
operator workspace:

```bash
set -euo pipefail

: "${WORKSPACE_HQ:?set WORKSPACE_HQ to the operator workspace root}"

RUN_ID="sagas-$(date -u +%Y%m%dT%H%M%SZ)"
RUN_ROOT="$WORKSPACE_HQ/.tmp/$RUN_ID"
mkdir -p "$RUN_ROOT"
cd "$RUN_ROOT"

cat > resolve-pins.py <<'PY'
from __future__ import annotations

import json
import re
import urllib.request


def read_json(url: str) -> dict:
    with urllib.request.urlopen(url, timeout=30) as response:
        return json.loads(response.read().decode("utf-8"))


packagist = read_json("https://repo.packagist.org/p2/durable-workflow/workflow.json")
php_version = next(
    package["version"]
    for package in packagist["packages"]["durable-workflow/workflow"]
    if re.match(r"^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?$", package["version"])
)

pypi = read_json("https://pypi.org/pypi/durable-workflow/json")
python_version = pypi["info"]["version"]

docker_hub = read_json("https://hub.docker.com/v2/repositories/durableworkflow/server/tags?page_size=100")
server_tag = next(
    tag["name"]
    for tag in docker_hub["results"]
    if re.match(r"^\d+\.\d+(?:\.\d+)?(?:[-A-Za-z0-9.]+)?$", tag["name"])
)

print(json.dumps(
    {
        "server_image": f"durableworkflow/server:{server_tag}",
        "php_package": "durable-workflow/workflow",
        "php_version": php_version,
        "python_package": "durable-workflow",
        "python_version": python_version,
    },
    indent=2,
    sort_keys=True,
))
PY

python3 resolve-pins.py > pins.json

SERVER_IMAGE="$(python3 -c 'import json; print(json.load(open("pins.json"))["server_image"])')"
PHP_VERSION="$(python3 -c 'import json; print(json.load(open("pins.json"))["php_version"])')"
PYTHON_VERSION="$(python3 -c 'import json; print(json.load(open("pins.json"))["python_version"])')"

docker pull "$SERVER_IMAGE"
SERVER_IMAGE_PIN="$(docker image inspect --format '{{index .RepoDigests 0}}' "$SERVER_IMAGE")"
docker tag "$SERVER_IMAGE_PIN" durable-workflow-sagas-server:run
printf '%s\n' "$SERVER_IMAGE_PIN" > server-image-digest.txt

python3 -m venv .venv
. .venv/bin/activate
pip install --upgrade pip
pip install "durable-workflow==$PYTHON_VERSION" httpx

mkdir -p php-worker
docker run --rm -v "$RUN_ROOT/php-worker:/app" composer:2 \
  composer require --no-interaction --no-progress "durable-workflow/workflow:$PHP_VERSION"

python - <<'PY'
import json
from pathlib import Path

pins = json.loads(Path("pins.json").read_text())
pins["experiment"] = "sagas"
pins["server_image_digest"] = Path("server-image-digest.txt").read_text().strip()
Path("run-metadata.json").write_text(json.dumps(pins, indent=2, sort_keys=True) + "\n")
PY
```

## Repos In Scope

Consult these repos read-only to understand the current contract:

| Repo | Branch | Read-only purpose |
| --- | --- | --- |
| `repos/workflow` | `v2` | PHP saga API, golden history, and v2 saga feature tests. |
| `repos/sdk-python` | `main` | Python workflow command/replay surface and child failure behavior. |
| `repos/server` | `main` | Published image usage, worker protocol, and history/output endpoints. |

Do not run `composer install`, `pip install -e`, `npm install`, or any test
command from a `repos/*` checkout. The only writable path is the run sandbox.

## Required Workflow

Every language runs the same logical workflow type:

```json
{
  "workflow_type": "<php-or-python>.saga",
  "input": {
    "order_id": "saga-001",
    "fail_after": "charge_payment",
    "pause_after_first_compensation": false,
    "steps": [
      {"action": "reserve_inventory", "compensation": "release_inventory"},
      {"action": "charge_payment", "compensation": "refund_payment"},
      {"action": "book_shipping", "compensation": "cancel_shipping"}
    ]
  }
}
```

The implementation rules are:

1. Execute `steps` in order.
2. After each successful forward action, declare the matching compensation.
3. For a success case, run all forward actions and complete with
   `status=completed`; no compensation activity may run.
4. For a failure case, fail immediately after `fail_after`. Completed forward
   actions must compensate in reverse order, then complete with
   `status=compensated`.
5. For recovery, run the failure case with `pause_after_first_compensation`
   enabled, stop the worker after the first compensation has completed and the
   workflow is still non-terminal, restart the worker, and wait for completion.
   The final activity log must contain each expected compensation exactly once.
   The terminal `ActivityCompleted` history must also contain exactly the
   expected activity sequence and counts, so duplicate compensation recorded in
   durable history cannot pass on replayed workflow output alone.

Required scenarios:

| Scenario | Language | Expected activity log |
| --- | --- | --- |
| `success` | PHP | `reserve_inventory`, `charge_payment`, `book_shipping` |
| `success` | Python | `reserve_inventory`, `charge_payment`, `book_shipping` |
| `failure` | PHP | `reserve_inventory`, `charge_payment`, `refund_payment`, `release_inventory` |
| `failure` | Python | `reserve_inventory`, `charge_payment`, `refund_payment`, `release_inventory` |
| `recovery` | PHP | same as `failure`, with no duplicate compensation after restart |
| `recovery` | Python | same as `failure`, with no duplicate compensation after restart |

Recovery history must include the restart marker activity in this exact
`ActivityCompleted` order: `reserve_inventory`, `charge_payment`,
`refund_payment`, `pause_after_refund`, `release_inventory`. Non-recovery
history must match the expected activity log exactly.

The failure trigger should be a failing child workflow named
`<language>.saga.failure`. This matches the currently implemented PHP and
Python replay surfaces. When Python starts surfacing activity failures as
catchable workflow exceptions, add an activity-failure variant to this
experiment and keep the child-failure variant as a replay-compatibility case.

## Runnable Harness

Create the harness files inside the sandbox. The PHP worker uses the published
PHP workflow package resolved above. Its worker-protocol loop is only an
adapter: it instantiates a `Workflow\V2\Workflow` subclass, drives it through
`WorkflowExecution`, and maps the yielded activity, child-workflow, and timer
calls to protocol commands. The PHP protocol loop must not encode saga
compensation order itself; compensation must come from
`addCompensation()` and `compensate()` inside the workflow subclass. The
Python worker uses the published Python SDK version resolved above.

```bash
cat > compose.yml <<'YAML'
services:
  server:
    image: durable-workflow-sagas-server:run
    environment:
      DW_AUTH_DRIVER: token
      DW_AUTH_TOKEN: sagas-token
      DW_WORKER_POLL_TIMEOUT: "1"
      DW_WORKER_POLL_INTERVAL_MS: "100"
    ports:
      - "8080:8080"
    volumes:
      - server-db:/app/database

volumes:
  server-db:
YAML

cat > php-worker/worker.php <<'PHP'
<?php
declare(strict_types=1);

use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\TimerCall;
use Workflow\V2\Support\WorkflowExecution;
use Workflow\V2\Workflow;

require __DIR__.'/vendor/autoload.php';

const BASE_URL = 'http://localhost:8080/api';
const TOKEN = 'sagas-token';
const NAMESPACE_NAME = 'default';
const PROTOCOL_VERSION = '1.2';
const TASK_QUEUE = 'sagas';
const WORKER_ID = 'php-sagas-worker';

#[Type('php.saga.failure')]
final class PhpSagaFailureWorkflow extends Workflow
{
    public function handle(array $payload): array
    {
        throw new \RuntimeException('planned saga failure');
    }
}

#[Type('php.saga')]
final class PhpSagaWorkflow extends Workflow
{
    public function handle(array $payload): array
    {
        $failAfter = $payload['fail_after'] ?? null;
        $failAfter = is_string($failAfter) && $failAfter !== '' ? $failAfter : null;
        $pause = (bool) ($payload['pause_after_first_compensation'] ?? false);
        $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [
            ['action' => 'reserve_inventory', 'compensation' => 'release_inventory'],
            ['action' => 'charge_payment', 'compensation' => 'refund_payment'],
            ['action' => 'book_shipping', 'compensation' => 'cancel_shipping'],
        ];
        $completed = [];

        try {
            foreach ($steps as $step) {
                if (! is_array($step)) {
                    continue;
                }

                $action = (string) ($step['action'] ?? '');
                $compensation = (string) ($step['compensation'] ?? '');

                if ($action === '') {
                    continue;
                }

                Workflow::activity($action, $payload);
                $completed[] = $action;

                if ($compensation !== '') {
                    $this->addCompensation(function () use ($compensation, $payload, $pause, &$completed): void {
                        Workflow::activity($compensation, $payload);
                        $completed[] = $compensation;

                        if ($pause && $compensation === 'refund_payment') {
                            Workflow::activity('pause_after_refund', $payload);
                            $completed[] = 'pause_after_refund';
                            Workflow::timer(5);
                        }
                    });
                }

                if ($failAfter !== null && $action === $failAfter) {
                    Workflow::child('php.saga.failure', $payload);
                }
            }

            return ['status' => 'completed', 'activity_log' => $completed];
        } catch (\Throwable) {
            $this->compensate();

            return ['status' => 'compensated', 'activity_log' => $completed];
        }
    }
}

function request_json(string $method, string $path, ?array $body = null, int $timeout = 10, array $allowed = []): array
{
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer '.TOKEN,
        'X-Namespace: '.NAMESPACE_NAME,
        'X-Durable-Workflow-Protocol-Version: '.PROTOCOL_VERSION,
    ];
    $options = ['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => $timeout,
    ]];
    if ($body !== null) {
        $options['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
    }
    unset($http_response_header);
    $response = file_get_contents(BASE_URL.$path, false, stream_context_create($options));
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }
    if (($status >= 400 || $status === 0) && ! in_array($status, $allowed, true)) {
        throw new RuntimeException("$method $path failed with HTTP $status: ".($response ?: ''));
    }
    $decoded = $response === false || $response === '' ? [] : json_decode($response, true, flags: JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}

function envelope(mixed $value, ?string $codec = null): array
{
    $codec = $codec ?: CodecRegistry::defaultCodec();
    return ['codec' => $codec, 'blob' => Serializer::serializeWithCodec($codec, $value)];
}

function decode_payload(mixed $value, ?string $codec = null): mixed
{
    if ($value === null) {
        return null;
    }
    if (is_array($value) && isset($value['codec'], $value['blob'])) {
        return Serializer::unserializeWithCodec((string) $value['codec'], (string) $value['blob']);
    }
    if (is_string($value)) {
        return Serializer::unserializeWithCodec($codec ?: CodecRegistry::defaultCodec(), $value);
    }
    return $value;
}

function task_codec(array $task): string
{
    $codec = $task['payload_codec'] ?? null;
    if (! is_string($codec) || $codec === '') {
        $codec = is_array($task['arguments'] ?? null) ? ($task['arguments']['codec'] ?? null) : null;
    }
    return is_string($codec) && $codec !== '' ? $codec : CodecRegistry::defaultCodec();
}

function history_events(array $task): array
{
    $events = $task['history_events'] ?? ($task['history']['events'] ?? []);
    return is_array($events) ? $events : [];
}

function event_sequence(array $event): ?int
{
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    $sequence = $payload['sequence'] ?? $event['sequence'] ?? null;

    return is_int($sequence) ? $sequence : null;
}

function event_for_sequence(array $task, int $sequence, array $eventTypes): ?array
{
    foreach (history_events($task) as $event) {
        if (! is_array($event)) {
            continue;
        }

        if (! in_array($event['event_type'] ?? null, $eventTypes, true)) {
            continue;
        }

        if (event_sequence($event) === $sequence) {
            return $event;
        }
    }

    return null;
}

function decode_history_value(mixed $value, string $codec): mixed
{
    if ($value === null) {
        return null;
    }

    if (is_array($value) && isset($value['codec'], $value['blob'])) {
        return Serializer::unserializeWithCodec((string) $value['codec'], (string) $value['blob']);
    }

    if (is_string($value)) {
        return Serializer::unserializeWithCodec($codec, $value);
    }

    return $value;
}

function event_payload(array $event): array
{
    $payload = $event['payload'] ?? [];

    return is_array($payload) ? $payload : [];
}

function activity_result(array $event, string $codec): mixed
{
    $payload = event_payload($event);
    $payloadCodec = is_string($payload['payload_codec'] ?? null) && $payload['payload_codec'] !== ''
        ? $payload['payload_codec']
        : $codec;

    return decode_history_value($payload['result'] ?? null, $payloadCodec);
}

function child_result(array $event, string $codec): mixed
{
    $payload = event_payload($event);
    $payloadCodec = is_string($payload['payload_codec'] ?? null) && $payload['payload_codec'] !== ''
        ? $payload['payload_codec']
        : $codec;

    return decode_history_value($payload['output'] ?? null, $payloadCodec);
}

function failure_from_event(array $event, string $fallback): \RuntimeException
{
    $payload = event_payload($event);
    $message = $payload['message'] ?? null;
    if (! is_string($message) || $message === '') {
        $exception = $payload['exception'] ?? null;
        $message = is_array($exception) && is_string($exception['message'] ?? null)
            ? $exception['message']
            : $fallback;
    }

    return new \RuntimeException($message);
}

function complete_workflow_task(array $task, array $commands): void
{
    request_json('POST', '/worker/workflow-tasks/'.$task['task_id'].'/complete', [
        'lease_owner' => $task['lease_owner'],
        'workflow_task_attempt' => $task['workflow_task_attempt'] ?? 1,
        'commands' => $commands,
    ], 10, [409]);
}

function complete_activity_task(array $task, mixed $result, string $codec): void
{
    request_json('POST', '/worker/activity-tasks/'.$task['task_id'].'/complete', [
        'activity_attempt_id' => $task['activity_attempt_id'] ?? $task['attempt_id'] ?? '',
        'lease_owner' => $task['lease_owner'],
        'result' => envelope($result, $codec),
    ], 10, [409]);
}

function fail_workflow_task(array $task, \Throwable $throwable): void
{
    complete_workflow_task($task, [[
        'type' => 'fail_workflow',
        'message' => $throwable->getMessage(),
        'exception_type' => $throwable::class,
        'exception' => [
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
        ],
    ]]);
}

function workflow_input(array $task, string $codec): array
{
    $input = decode_payload($task['arguments'] ?? null, $codec);
    $input = is_array($input) && array_is_list($input) ? ($input[0] ?? []) : $input;
    return is_array($input) ? $input : [];
}

function workflow_run(array $task, string $codec): WorkflowRun
{
    $run = new WorkflowRun();
    $run->id = (string) ($task['run_id'] ?? $task['workflow_run_id'] ?? '');
    $run->workflow_instance_id = (string) ($task['workflow_id'] ?? $task['workflow_instance_id'] ?? '');
    $run->workflow_type = (string) ($task['workflow_type'] ?? '');
    $run->payload_codec = $codec;

    return $run;
}

function workflow_for_task(array $task, WorkflowRun $run): Workflow
{
    return match ($task['workflow_type'] ?? '') {
        'php.saga' => new PhpSagaWorkflow($run),
        'php.saga.failure' => new PhpSagaFailureWorkflow($run),
        default => throw new \RuntimeException('unknown PHP workflow type '.var_export($task['workflow_type'] ?? null, true)),
    };
}

function complete_current_call(array $task, mixed $current, int $sequence, string $codec): bool
{
    if ($current instanceof ActivityCall) {
        $event = event_for_sequence($task, $sequence, [
            'ActivityCompleted',
            'ActivityFailed',
            'ActivityCancelled',
            'ActivityTimedOut',
        ]);

        if (is_array($event)) {
            return false;
        }

        complete_workflow_task($task, [[
            'type' => 'schedule_activity',
            'activity_type' => $current->activity,
            'queue' => TASK_QUEUE,
            'arguments' => envelope($current->arguments, $codec),
        ]]);

        return true;
    }

    if ($current instanceof ChildWorkflowCall) {
        $event = event_for_sequence($task, $sequence, [
            'ChildRunCompleted',
            'ChildRunFailed',
            'ChildRunCancelled',
            'ChildRunTerminated',
        ]);

        if (is_array($event)) {
            return false;
        }

        complete_workflow_task($task, [[
            'type' => 'start_child_workflow',
            'workflow_type' => $current->workflow,
            'queue' => TASK_QUEUE,
            'arguments' => envelope($current->arguments, $codec),
        ]]);

        return true;
    }

    if ($current instanceof TimerCall) {
        $event = event_for_sequence($task, $sequence, ['TimerFired']);

        if (is_array($event)) {
            return false;
        }

        complete_workflow_task($task, [[
            'type' => 'start_timer',
            'delay_seconds' => $current->seconds,
        ]]);

        return true;
    }

    throw new \RuntimeException('unsupported PHP workflow yield '.get_debug_type($current));
}

function replay_event(array $event, mixed $current, string $codec): mixed
{
    $eventType = $event['event_type'] ?? null;

    if ($current instanceof ActivityCall) {
        if ($eventType === 'ActivityCompleted') {
            return activity_result($event, $codec);
        }

        throw failure_from_event($event, 'activity failed');
    }

    if ($current instanceof ChildWorkflowCall) {
        if ($eventType === 'ChildRunCompleted') {
            return child_result($event, $codec);
        }

        throw failure_from_event($event, 'child workflow failed');
    }

    if ($current instanceof TimerCall) {
        return null;
    }

    throw new \RuntimeException('unsupported PHP workflow yield '.get_debug_type($current));
}

function resolution_event(array $task, mixed $current, int $sequence): ?array
{
    if ($current instanceof ActivityCall) {
        return event_for_sequence($task, $sequence, [
            'ActivityCompleted',
            'ActivityFailed',
            'ActivityCancelled',
            'ActivityTimedOut',
        ]);
    }

    if ($current instanceof ChildWorkflowCall) {
        return event_for_sequence($task, $sequence, [
            'ChildRunCompleted',
            'ChildRunFailed',
            'ChildRunCancelled',
            'ChildRunTerminated',
        ]);
    }

    if ($current instanceof TimerCall) {
        return event_for_sequence($task, $sequence, ['TimerFired']);
    }

    return null;
}

function handle_workflow_task(array $task): void
{
    $codec = task_codec($task);
    $run = workflow_run($task, $codec);
    $workflow = workflow_for_task($task, $run);
    $input = workflow_input($task, $codec);

    try {
        $execution = WorkflowExecution::start($workflow, [$input]);
        $sequence = 1;

        while ($execution->valid()) {
            $current = $execution->current();
            $event = resolution_event($task, $current, $sequence);

            if (is_array($event)) {
                try {
                    $value = replay_event($event, $current, $codec);
                    $execution->send($value);
                } catch (\Throwable $throwable) {
                    $execution->throw($throwable);
                }

                $sequence++;
                continue;
            }

            if (complete_current_call($task, $current, $sequence, $codec)) {
                return;
            }
        }

        complete_workflow_task($task, [[
            'type' => 'complete_workflow',
            'result' => envelope($execution->getReturn(), $codec),
        ]]);
    } catch (\Throwable $throwable) {
        fail_workflow_task($task, $throwable);
    }
}

function handle_activity_task(array $task): void
{
    $codec = task_codec($task);
    $activityType = (string) ($task['activity_type'] ?? '');
    complete_activity_task($task, ['activity' => $activityType], $codec);
}

request_json('POST', '/worker/register', [
    'worker_id' => WORKER_ID,
    'task_queue' => TASK_QUEUE,
    'runtime' => 'php',
    'sdk_version' => 'durable-workflow-php/published-image',
    'supported_workflow_types' => ['php.saga', 'php.saga.failure'],
    'supported_activity_types' => [
        'reserve_inventory',
        'charge_payment',
        'book_shipping',
        'refund_payment',
        'release_inventory',
        'cancel_shipping',
        'pause_after_refund',
    ],
    'max_concurrent_workflow_tasks' => 1,
    'max_concurrent_activity_tasks' => 1,
]);

while (true) {
    $workflowPoll = request_json('POST', '/worker/workflow-tasks/poll', [
        'worker_id' => WORKER_ID,
        'task_queue' => TASK_QUEUE,
    ], 6);
    if (is_array($workflowPoll['task'] ?? null)) {
        handle_workflow_task($workflowPoll['task']);
    }

    $activityPoll = request_json('POST', '/worker/activity-tasks/poll', [
        'worker_id' => WORKER_ID,
        'task_queue' => TASK_QUEUE,
    ], 6);
    if (is_array($activityPoll['task'] ?? null)) {
        handle_activity_task($activityPoll['task']);
    }
    usleep(100000);
}
PHP

cat > python-worker.py <<'PY'
from __future__ import annotations

import asyncio

from durable_workflow import Client, Worker, activity, workflow
from durable_workflow.errors import ChildWorkflowFailed


TASK_QUEUE = "sagas"


@activity.defn(name="reserve_inventory")
def reserve_inventory(payload: dict) -> dict:
    return {"activity": "reserve_inventory", "order_id": payload["order_id"]}


@activity.defn(name="charge_payment")
def charge_payment(payload: dict) -> dict:
    return {"activity": "charge_payment", "order_id": payload["order_id"]}


@activity.defn(name="book_shipping")
def book_shipping(payload: dict) -> dict:
    return {"activity": "book_shipping", "order_id": payload["order_id"]}


@activity.defn(name="refund_payment")
def refund_payment(payload: dict) -> dict:
    return {"activity": "refund_payment", "order_id": payload["order_id"]}


@activity.defn(name="release_inventory")
def release_inventory(payload: dict) -> dict:
    return {"activity": "release_inventory", "order_id": payload["order_id"]}


@activity.defn(name="cancel_shipping")
def cancel_shipping(payload: dict) -> dict:
    return {"activity": "cancel_shipping", "order_id": payload["order_id"]}


@activity.defn(name="pause_after_refund")
def pause_after_refund(payload: dict) -> dict:
    # Recovery scenarios use this marker as a deterministic restart point.
    # The assertion filters it from the saga activity log.
    return {"activity": "pause_after_refund", "order_id": payload["order_id"]}


@workflow.defn(name="python.saga.failure")
class PythonSagaFailure:
    def run(self, ctx, payload: dict):
        raise RuntimeError("planned saga failure")


@workflow.defn(name="python.saga")
class PythonSaga:
    def run(self, ctx, payload: dict):
        fail_after = payload.get("fail_after")
        pause = bool(payload.get("pause_after_first_compensation"))
        steps = payload.get("steps")
        if not isinstance(steps, list):
            steps = [
                {"action": "reserve_inventory", "compensation": "release_inventory"},
                {"action": "charge_payment", "compensation": "refund_payment"},
                {"action": "book_shipping", "compensation": "cancel_shipping"},
            ]
        completed: list[str] = []
        compensations: list[str] = []
        try:
            for step in steps:
                if not isinstance(step, dict):
                    continue
                activity_name = step.get("action")
                compensation = step.get("compensation")
                if not isinstance(activity_name, str) or activity_name == "":
                    continue
                yield ctx.schedule_activity(activity_name, [payload])
                completed.append(activity_name)
                if isinstance(compensation, str) and compensation != "":
                    compensations.append(compensation)
                if fail_after and activity_name == fail_after:
                    yield ctx.start_child_workflow("python.saga.failure", [payload], task_queue=TASK_QUEUE)
            return {"status": "completed", "activity_log": completed}
        except ChildWorkflowFailed:
            for index, compensation in enumerate(reversed(compensations)):
                yield ctx.schedule_activity(compensation, [payload])
                completed.append(compensation)
                if pause and index == 0:
                    yield ctx.schedule_activity("pause_after_refund", [payload])
                    completed.append("pause_after_refund")
                    yield ctx.sleep(5)
            return {"status": "compensated", "activity_log": completed}


async def main() -> None:
    client = Client("http://localhost:8080", token="sagas-token", namespace="default")
    worker = Worker(
        client,
        task_queue=TASK_QUEUE,
        workflows=[PythonSaga, PythonSagaFailure],
        activities=[
            reserve_inventory,
            charge_payment,
            book_shipping,
            refund_payment,
            release_inventory,
            cancel_shipping,
            pause_after_refund,
        ],
        worker_id="python-sagas-worker",
        max_concurrent_workflow_tasks=1,
        max_concurrent_activity_tasks=1,
    )
    await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
PY
```

Run the server, workers, and orchestrator:

```bash
docker compose -f compose.yml run --rm server server-bootstrap
docker compose -f compose.yml up -d --wait

docker rm -f sagas-php-worker >/dev/null 2>&1 || true
docker run -d --name sagas-php-worker --network host \
  -v "$RUN_ROOT/php-worker:/work" \
  -w /work \
  composer:2 php worker.php

. "$RUN_ROOT/.venv/bin/activate"
python -u "$RUN_ROOT/python-worker.py" > "$RUN_ROOT/python-worker.log" 2>&1 &
PYTHON_WORKER_PID=$!
export RUN_ROOT PYTHON_WORKER_PID

cat > orchestrate.py <<'PY'
from __future__ import annotations

import asyncio
import contextlib
import json
import os
import signal
import subprocess
import time
from collections import Counter
from pathlib import Path

from durable_workflow import Client, serializer


EXPECTED = {
    "success": ["reserve_inventory", "charge_payment", "book_shipping"],
    "failure": ["reserve_inventory", "charge_payment", "refund_payment", "release_inventory"],
}
EXPECTED_RECOVERY_HISTORY = [
    "reserve_inventory",
    "charge_payment",
    "refund_payment",
    "pause_after_refund",
    "release_inventory",
]
TERMINAL_WORKFLOW_STATUSES = {"completed", "failed", "terminated", "canceled", "cancelled"}


def compact_state(desc: dict | None) -> dict[str, object]:
    if not isinstance(desc, dict):
        return {"status": None, "is_terminal": False}
    status = desc.get("status")
    return {
        "status": status,
        "is_terminal": bool(desc.get("is_terminal") or status in TERMINAL_WORKFLOW_STATUSES),
        "run_id": desc.get("run_id"),
        "workflow_id": desc.get("workflow_id"),
        "error": desc.get("error") or desc.get("failure") or desc.get("exception"),
    }


async def wait_result(
    client: Client,
    workflow_id: str,
    failures: list[str],
    timeout: float = 90.0,
) -> dict:
    deadline = time.monotonic() + timeout
    last_desc: dict | None = None
    while time.monotonic() < deadline:
        desc = await client._request("GET", f"/workflows/{workflow_id}")
        last_desc = desc
        status = desc.get("status")
        if desc.get("is_terminal") or status in TERMINAL_WORKFLOW_STATUSES:
            if status != "completed":
                failures.append(
                    f"{workflow_id} expected completed terminal workflow, got {compact_state(desc)}"
                )
                return {}
            envelope = desc.get("output_envelope")
            if envelope is not None:
                return serializer.decode_envelope(envelope)
            output = desc.get("output")
            return output if isinstance(output, dict) else {"raw": output}
        await asyncio.sleep(0.5)
    failures.append(
        f"{workflow_id} timed out after {timeout:.0f}s waiting for completion; "
        f"last_state={compact_state(last_desc)}"
    )
    return {}


async def terminal_state(
    client: Client,
    workflow_id: str,
    failures: list[str] | None = None,
) -> dict[str, object]:
    try:
        desc = await client._request("GET", f"/workflows/{workflow_id}")
    except Exception as exc:
        if failures is not None:
            failures.append(f"{workflow_id} state lookup failed: {type(exc).__name__}: {exc}")
        return {"status": None, "is_terminal": False, "error": f"{type(exc).__name__}: {exc}"}
    return compact_state(desc)


async def start_handle(client: Client, workflow_type: str, workflow_id: str, payload: dict):
    return await client.start_workflow(
        workflow_type=workflow_type,
        workflow_id=workflow_id,
        task_queue="sagas",
        input=[payload],
    )


def counts(items: list[str]) -> dict[str, int]:
    return dict(sorted(Counter(items).items()))


def completed_activity_types(history: dict) -> list[str]:
    events = history.get("events")
    if not isinstance(events, list):
        events = ((history.get("history") or {}).get("events") or [])
    types: list[str] = []
    for event in events:
        if event.get("event_type") != "ActivityCompleted":
            continue
        payload = event.get("payload") or {}
        activity_type = payload.get("activity_type") or payload.get("activity_name")
        if isinstance(activity_type, str):
            types.append(activity_type)
    return types


async def assert_completed_activity_history(
    client: Client,
    workflow_id: str,
    run_id: str,
    expected: list[str],
    failures: list[str],
    activity_history: dict[str, list[str]],
) -> None:
    try:
        history = await client.get_history(workflow_id, run_id)
    except Exception as exc:
        activity_history[workflow_id] = []
        failures.append(f"{workflow_id} history lookup failed: {type(exc).__name__}: {exc}")
        return
    actual = completed_activity_types(history)
    activity_history[workflow_id] = actual
    if actual != expected:
        failures.append(
            f"{workflow_id} ActivityCompleted history expected sequence {expected}, got {actual}; "
            f"expected_counts={counts(expected)}, actual_counts={counts(actual)}"
        )


async def wait_for_activity(
    client: Client,
    workflow_id: str,
    run_id: str,
    activity_type: str,
    failures: list[str],
) -> bool:
    deadline = time.monotonic() + 60
    last_history: list[str] = []
    last_error: str | None = None
    while time.monotonic() < deadline:
        try:
            history = await client.get_history(workflow_id, run_id)
            last_history = completed_activity_types(history)
            if activity_type in last_history:
                return True
        except Exception as exc:
            last_error = f"{type(exc).__name__}: {exc}"
        await asyncio.sleep(0.5)
    detail = f"last_activity_history={last_history}"
    if last_error is not None:
        detail = f"{detail}, last_error={last_error}"
    failures.append(f"{workflow_id} did not record {activity_type} within 60s; {detail}")
    return False


async def assert_non_terminal_restart_point(
    client: Client,
    workflow_id: str,
    failures: list[str],
    restart_points: dict[str, dict[str, object]],
) -> bool:
    state = await terminal_state(client, workflow_id, failures)
    restart_points[workflow_id] = state
    if state.get("is_terminal"):
        failures.append(
            f"{workflow_id} expected non-terminal state after pause_after_refund before worker "
            f"restart, got {state}"
        )
        return False
    return True


def restart_python_worker() -> subprocess.Popen:
    pid = int(os.environ["PYTHON_WORKER_PID"])
    with contextlib.suppress(ProcessLookupError):
        os.kill(pid, signal.SIGTERM)
    time.sleep(1)
    log = open(Path(os.environ["RUN_ROOT"]) / "python-worker-restart.log", "ab", buffering=0)
    return subprocess.Popen(
        ["python", "-u", str(Path(os.environ["RUN_ROOT"]) / "python-worker.py")],
        stdout=log,
        stderr=subprocess.STDOUT,
    )


def restart_php_worker() -> None:
    run_root = os.environ["RUN_ROOT"]
    subprocess.run(["docker", "rm", "-f", "sagas-php-worker"], check=False)
    subprocess.run([
        "docker",
        "run",
        "-d",
        "--name",
        "sagas-php-worker",
        "--network",
        "host",
        "-v",
        f"{run_root}/php-worker:/work",
        "-w",
        "/work",
        "composer:2",
        "php",
        "worker.php",
    ], check=True)


def status_terminal_tuple(output: dict, terminal: dict[str, object]) -> tuple[object, object, object]:
    return (
        output.get("status"),
        terminal.get("status"),
        terminal.get("is_terminal"),
    )


def assert_status_terminal_parity(
    failures: list[str],
    scenario: str,
    php_output: dict,
    php_terminal: dict[str, object],
    python_output: dict,
    python_terminal: dict[str, object],
) -> None:
    php_state = status_terminal_tuple(php_output, php_terminal)
    python_state = status_terminal_tuple(python_output, python_terminal)
    if php_state != python_state:
        failures.append(
            f"sagas {scenario} PHP/Python output.status and terminal state differ: "
            f"php={php_state}, python={python_state}"
        )


async def main() -> None:
    client = Client("http://localhost:8080", token="sagas-token", namespace="default")
    results: dict[str, dict] = {}
    terminals: dict[str, dict[str, object]] = {}
    activity_history: dict[str, list[str]] = {}
    restart_points: dict[str, dict[str, object]] = {}
    failures: list[str] = []

    base = {
        "order_id": "saga-001",
        "steps": [
            {"action": "reserve_inventory", "compensation": "release_inventory"},
            {"action": "charge_payment", "compensation": "refund_payment"},
            {"action": "book_shipping", "compensation": "cancel_shipping"},
        ],
    }

    try:
        for language in ["php", "python"]:
            for scenario, fail_after in [("success", None), ("failure", "charge_payment")]:
                workflow_id = f"sagas-{language}-{scenario}"
                handle = await start_handle(
                    client,
                    f"{language}.saga",
                    workflow_id,
                    {**base, "fail_after": fail_after},
                )
                output = await wait_result(client, workflow_id, failures)
                results[workflow_id] = output
                terminals[workflow_id] = await terminal_state(client, workflow_id, failures)
                expected = EXPECTED[scenario]
                actual = [item for item in output.get("activity_log", []) if item != "pause_after_refund"]
                if actual != expected:
                    failures.append(f"{workflow_id} activity_log expected {expected}, got {actual}")
                expected_status = "completed" if scenario == "success" else "compensated"
                if output.get("status") != expected_status:
                    failures.append(f"{workflow_id} output.status expected {expected_status}, got {output.get('status')}")
                await assert_completed_activity_history(
                    client,
                    workflow_id,
                    handle.run_id,
                    expected,
                    failures,
                    activity_history,
                )

        for scenario in ["success", "failure"]:
            assert_status_terminal_parity(
                failures,
                scenario,
                results[f"sagas-php-{scenario}"],
                terminals[f"sagas-php-{scenario}"],
                results[f"sagas-python-{scenario}"],
                terminals[f"sagas-python-{scenario}"],
            )

        workflow_id = "sagas-python-recovery"
        handle = await start_handle(
            client,
            "python.saga",
            workflow_id,
            {**base, "fail_after": "charge_payment", "pause_after_first_compensation": True},
        )
        observed_pause = await wait_for_activity(
            client,
            workflow_id,
            handle.run_id,
            "pause_after_refund",
            failures,
        )
        restarted_python = None
        if observed_pause and await assert_non_terminal_restart_point(
            client,
            workflow_id,
            failures,
            restart_points,
        ):
            restarted_python = restart_python_worker()
        output = await wait_result(client, workflow_id, failures)
        if restarted_python is not None:
            restarted_python.terminate()
        results[workflow_id] = output
        terminals[workflow_id] = await terminal_state(client, workflow_id, failures)
        actual = [item for item in output.get("activity_log", []) if item != "pause_after_refund"]
        if actual != EXPECTED["failure"]:
            failures.append(f"{workflow_id} recovery expected {EXPECTED['failure']}, got {actual}")
        if output.get("status") != "compensated":
            failures.append(f"{workflow_id} output.status expected compensated, got {output.get('status')}")
        await assert_completed_activity_history(
            client,
            workflow_id,
            handle.run_id,
            EXPECTED_RECOVERY_HISTORY,
            failures,
            activity_history,
        )

        php_workflow_id = "sagas-php-recovery"
        php_handle = await start_handle(
            client,
            "php.saga",
            php_workflow_id,
            {**base, "fail_after": "charge_payment", "pause_after_first_compensation": True},
        )
        observed_php_pause = await wait_for_activity(
            client,
            php_workflow_id,
            php_handle.run_id,
            "pause_after_refund",
            failures,
        )
        if observed_php_pause and await assert_non_terminal_restart_point(
            client,
            php_workflow_id,
            failures,
            restart_points,
        ):
            restart_php_worker()
        php_recovery = await wait_result(client, php_workflow_id, failures)
        results["sagas-php-recovery"] = php_recovery
        terminals["sagas-php-recovery"] = await terminal_state(client, php_workflow_id, failures)
        actual = [item for item in php_recovery.get("activity_log", []) if item != "pause_after_refund"]
        if actual != EXPECTED["failure"]:
            failures.append(f"sagas-php-recovery expected {EXPECTED['failure']}, got {actual}")
        if php_recovery.get("status") != "compensated":
            failures.append(f"sagas-php-recovery output.status expected compensated, got {php_recovery.get('status')}")
        await assert_completed_activity_history(
            client,
            php_workflow_id,
            php_handle.run_id,
            EXPECTED_RECOVERY_HISTORY,
            failures,
            activity_history,
        )
        assert_status_terminal_parity(
            failures,
            "recovery",
            php_recovery,
            terminals["sagas-php-recovery"],
            output,
            terminals[workflow_id],
        )
    except Exception as exc:
        failures.append(f"orchestrator failed before completing all checks: {type(exc).__name__}: {exc}")

    report = {
        "schema": "durable-workflow.conformance.sagas.v1",
        "status": "pass" if not failures else "fail",
        "results": results,
        "terminal_states": terminals,
        "restart_points": restart_points,
        "activity_history": activity_history,
        "failures": failures,
    }
    Path("sagas-result.json").write_text(json.dumps(report, indent=2, sort_keys=True) + "\n")
    print(json.dumps(report, indent=2, sort_keys=True))
    if failures:
        raise SystemExit(1)


if __name__ == "__main__":
    asyncio.run(main())
PY

python orchestrate.py

kill "$PYTHON_WORKER_PID" >/dev/null 2>&1 || true
docker rm -f sagas-php-worker >/dev/null 2>&1 || true
docker compose -f compose.yml down -v
```

## What To Capture As Bugs

Create a tracker finding for any of these outcomes:

- the experiment cannot resolve or install the latest published server image,
  PHP package, or Python package;
- either language fails to complete the success case;
- any compensation activity runs on a success case;
- failure after `charge_payment` does not run exactly `refund_payment` then
  `release_inventory`;
- recovery after partial compensation progress produces duplicate
  `refund_payment`, duplicate `release_inventory`, or omits either
  compensation;
- recovery reaches a terminal workflow state before the worker restart point
  after `pause_after_refund`;
- terminal `ActivityCompleted` history sequence or counts differ from the
  expected saga action sequence, including either recovery scenario;
- PHP and Python outputs differ in status, action order, compensation order,
  or terminal state for the same scenario;
- history contains a hidden saga-only event stream instead of normal activity,
  child-workflow, timer, and workflow events.

Suggested finding fields:

```text
Title: Sagas conformance: <short failure>
Labels: conformance, sagas, temporal-parity, pipeline:ready-item, state:pending
Body:
- Experiment: sagas
- Scenario: success | failure | recovery
- Language: PHP | Python | both
- Published pins: attach run-metadata.json
- Expected: <contract expectation>
- Actual: <observed result>
- Artifacts: attach sagas-result.json and relevant worker logs
```

## Output Format

The run must leave these files in the sandbox:

| File | Contents |
| --- | --- |
| `pins.json` | Published versions resolved before install. |
| `server-image-digest.txt` | Immutable server image digest used for the run. |
| `run-metadata.json` | Version pins plus experiment identity. |
| `sagas-result.json` | Machine-readable pass/fail report, including workflow outputs, terminal states, recovery restart-point states, and terminal `ActivityCompleted` sequences. |
| `python-worker.log` | Python worker stdout/stderr. |
| Docker logs for `sagas-php-worker` and `server` | Capture on failure before cleanup. |

Record the run after `sagas-result.json` exists:

```bash
"$WORKSPACE_HQ/bin/pipeline" conformance-record \
  --experiment=sagas \
  --status="$(python -c 'import json; print(json.load(open("sagas-result.json"))["status"])')" \
  --metadata="$RUN_ROOT/run-metadata.json" \
  --result="$RUN_ROOT/sagas-result.json"
```

If the recorder is unavailable, add a tracker comment with the same metadata
and attach `run-metadata.json` plus `sagas-result.json`; this is a temporary
manual fallback only.
