# Embedded activity leases

A lease records which attempt owns an activity. It is separate from the activity's
start-to-close, schedule-to-close and heartbeat timeouts, and from workflow run
and execution timeouts.

For committed activities on persistent storage, the runtime starts an independent
PHP metadata process before invoking the activity. The callback stays in its
original PHP process, with its Laravel services, captured state and connections.
The helper renews only that original attempt and stops when its owner exits.
It is joined before the callback outcome is recorded.

The helper uses the original database connection settings, including its TLS and
authentication configuration. Automatic maintenance needs `proc_open` and an
available PHP CLI executable. Laravel's existing Symfony executable finder locates the CLI binary
when the activity is invoked from another PHP SAPI. No activity arguments,
results or exception payloads are sent to the helper.

SQLite maintenance uses the database's current journal mode. Opening the helper
does not replay a journal-mode setting that the running application has changed.

If process creation or a PHP CLI binary is unavailable, the callback keeps its
original captured lease and deadline. Short activities retain their previous
behavior, but automatic renewal is unavailable and grants no additional time.

Runtime renewal records no heartbeat event and supplies no application progress.
Activities with a heartbeat timeout must still call `heartbeat()` with genuine
progress. Renewal cannot extend a recorded business deadline, revive an expired
grant, transfer the attempt to another worker, or authorize a late result.
Brief database contention retries only inside the last confirmed grant. Loss of
authority fences the outcome; it cannot interrupt arbitrary blocking PHP code or
undo an external side effect.

`DW_V2_ACTIVITY_TASK_LEASE_SECONDS` configures the ownership lease, with a default
of 300 seconds and valid values from 1 through 86400. Increasing it also increases
how long recovery may wait after an unannounced worker death. It is not an
activity execution timeout. Keep the usual worker, repair and timeout processing
running so expired attempts receive a durable recovery outcome.

In-memory databases, uncommitted outer transactions, mocked activities and a
frozen application test clock have no independently observable live grant. They
retain the captured lease without an independent helper. Use persistent storage
and a real clock when qualifying long-running queue workers. Custom database
connectors must be available to the fresh metadata process; application-specific
connector bootstraps are not qualified by the built-in driver tests.

Legacy activity jobs that start after their workflow has completed or failed
retain the original captured lease and outcome handling. They receive no new
renewal authority. An activity retained by the runtime's explicit abandon policy
can renew only within that policy's original recorded deadlines.
