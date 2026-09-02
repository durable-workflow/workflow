# Worker Sessions Runtime Contract

Worker sessions are the v2 activity-affinity primitive. They are used when a
sequence of activity attempts must reuse process-local state, GPU memory, a
mounted filesystem, or another worker-local resource across multiple durable
steps.

## Authoring API

PHP workflow authors create a session handle with
`Workflow::workerSession()` or `Workflow\V2\workerSession()` and schedule
activities through that handle:

```php
use Workflow\V2\Support\WorkerSessionOptions;
use Workflow\V2\Workflow;

$session = Workflow::workerSession(
    'gpu-render',
    new WorkerSessionOptions(
        queue: 'gpu-activities',
        requirements: ['gpu:nvidia-l4'],
        leaseSeconds: 120,
        ttlSeconds: 1800,
        maxConcurrentActivities: 1,
    ),
);

$result = $session->activity(RenderFramesActivity::class, $videoId);
```

The durable activity option snapshot stores the same contract under
`activity_options.worker_session`. External workers use the same shape on a
`schedule_activity` workflow-task command under the `worker_session` field.

## Lifecycle

Session creation is lazy. Matching creates or reacquires the session when the
first in-session activity task is admitted to a capable worker. A worker may
also create the session explicitly through the worker protocol before polling.

An active session ends when the holder closes it, the session lease expires,
the absolute TTL expires, or the holding worker is detected as failed. TTL
expiry is terminal for that session id; lease expiry and orphan detection may
be reacquired when `allow_reacquire_after_failure` is true.

## Lease And Ownership

At most one worker owns a session lease at a time. The server admits
in-session activity tasks only when one of these is true:

- no session exists and `create_if_missing` is true;
- the active session is already owned by the polling worker;
- the session is expired, failed, or orphaned and reacquisition is allowed.

Session ownership does not replace the per-attempt activity lease. Each
activity attempt still has its own `activity_attempt_id`, lease owner,
heartbeat, completion, failure, timeout, and cancellation path.

## Admission And Routing

Worker sessions participate in normal queue routing. `WorkerSessionOptions`
may set `connection` and `queue`; per-call `ActivityOptions` can still override
them. The server enforces session-specific admission after normal task-queue
admission:

- worker registration `capabilities` must cover every session requirement;
- `max_concurrent_activities` caps leased activity attempts inside the session;
- `max_concurrent_worker_sessions` caps active session leases held by one
  worker registration.

Fleet-specific routing uses plain capability strings such as
`gpu:nvidia-l4`, `gpu:a100`, `fs:/mnt/models`, or `zone:us-east-1a`.

Worker-session admission is fenced by the worker protocol version. Server
nodes advertising a protocol below
`WorkerProtocolVersion::workerSessionSemantics()['minimum_protocol_version']`
must reject explicit session lifecycle calls, reject `schedule_activity`
commands carrying `worker_session`, and avoid claiming existing
worker-session activity tasks. This keeps rolling server upgrades from mixing
nodes that understand the session lease protocol with nodes that can only
lease ordinary activity tasks.

## Renewal And Expiry

Activity heartbeats renew both the activity attempt lease and the session
lease. Workers may also renew the session directly through the worker protocol.
If the session lease expires, the session becomes `expired`; if the holding
worker registration heartbeat is stale or missing, the session becomes
`orphaned`.

## Holder Failure

When the holding worker dies mid-sequence, in-flight activities keep the
ordinary at-least-once semantics: their attempt leases expire, repair makes
them claimable again, and a stale completion may be rejected. A capable worker
may reacquire the session when the contract allows it. Workflow authors must
expect process-local state to be rebuilt after reacquisition.

## Cancellation And Shutdown

Workflow cancellation propagates through activity heartbeat responses. A
session lease never authorizes an in-session activity to ignore
`cancel_requested`. Planned worker shutdown should close held sessions through
the worker protocol before stopping the process; in-flight activities still
finish, fail, cancel, or expire under their own attempt leases.

## Visibility

The server exposes active, closed, expired, failed, and orphaned sessions
through operator APIs and cluster diagnostics. Visibility includes session id,
holder, queue, requirements, lease expiry, TTL expiry, active activity count,
and failure reason.

## Authoring Guidance

Prefer ordinary queued activities when each step is independent. Prefer one
larger activity when the whole operation is one atomic side effect. Use a
worker session only when multiple durable activity steps must reuse a
worker-local resource and the workflow can tolerate rebuilding that resource
after worker failure.
