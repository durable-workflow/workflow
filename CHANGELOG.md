# Changelog

## Unreleased

- Add a namespace-scoped open-run version-usage query with legacy/current/unknown
  counts and byte-exact keyset pages. Missing markers and unavailable history
  remain unknown when assessing legacy branch retirement.

- Experimental embedded compensation scopes (#1063): retained versioned
  obligations, native Saga recovery, sequential child participation and explicit
  release. Each scope keeps its originating run; continue-as-new, origin redrive
  and child retry do not transfer forward ownership. Recovery activities require
  the exact paired native admission receipt and retained scope/original-owner
  binding before host authorization and again under native activity locks.

- **Run migrations on MariaDB.** Timer status and service-dispatch metadata
  updates preserve their scheduled timestamps on older MariaDB schemas. The
  forward migrations remove implicit timestamp updates without rewriting
  retained rows. Condition occurrence
  reopens use the original scheduled history deadline, including when an older
  cancelled timer row has already drifted (#1139).

- Concurrent child completion keeps parent-history row locks on the existing
  per-run index on MySQL and MariaDB, avoiding an unrelated sibling history
  row in the parent barrier lock cycle. No new index or retry is introduced.

- Experimental embedded child-start milestone: `Workflow::startChild()` returns
  a versioned `StartedChildWorkflow` receipt after native child admission, and
  `$child->await()` observes that same child later. Cold replay reuses the
  original admission; a queued request alone cannot supply a receipt. The
  existing `child()` still waits for completion. This adds a
  `ChildWorkflowAwaited` history event and an optional
  `child_completion_mode` on `ChildWorkflowScheduled`, with no database
  migration. Worker-protocol execution refuses the new calls. State-bound
  business bindings and retained compensation remain separate #1069 work.

- Require patched Laravel 12.72 or 13.41 minimums for the current Composer
  security advisories. Keep existing application audits enabled and update
  application lockfiles before upgrading Workflow (#1133).
- Failed-run redrive preserves fractional seconds in replay clocks for the
  original run start and reused steps. Repeated redrive keeps previously
  recorded clock strings unchanged.

- Experimental domain entity references for top-level arguments of embedded
  queued and local activities (#996). Register an entity class, deterministic
  adapter and authorized resolver. History records a portable reference and
  argument positions. Each real attempt resolves current state outside the
  engine transaction; replay never calls the resolver. Local lookup retains
  the original hosting claim and history budget, and the callback keeps its
  existing transaction. Ordinary maps and
  unregistered objects retain their existing serialization behavior. See
  [the registration and retry contract](docs/entity-references.md).

- Failed-run redrive accepts a verified linear activity retry chain. A retried
  completed prefix is reused and only the failed boundary step runs again.
  Substituted attempt/task identities refuse with
  `invalid_activity_retry_history`; existing service-worker definition checks
  still apply. An exactly bound accepted start/adopt receipt is harmless, while
  rejected or mismatched starts remain unsupported. This core correction is
  separate from the experimental Saga compensation scope foundation (#1063).

- **Run migrations.** Opt-in deadline-triggered cleanup (#863, experimental,
  embedded). A start can reserve the last part of its hard execution or run
  timeout for cleanup with `StartOptions::withTimeoutCleanup($seconds)` (or
  `timeoutCleanupSeconds:`). When the work deadline passes (the hard deadline
  minus the allowance), the run enters timeout cleanup once: workflow code
  receives `WorkflowTimeoutCleanupRequested`, a cooperative cancellation
  request whose context has `cause: timeout`, so existing compensation
  handlers run. An idle run is woken at that time. The run always ends with
  `WorkflowTimedOut`, which now carries a `timeout_cleanup` outcome:
  `completed`, `failed` (the cleanup failure is linked), `expired` (still
  running at the hard deadline) or `preempted` (terminated or hard-cancelled
  first). The hard deadline never moves, and nothing changes without the
  option. Continue-as-new and redrive carry the allowance like the hard
  deadlines. New history event `WorkflowTimeoutCleanupStarted`; the cancellation
  context gains the optional top-level `cause`. The migration adds the nullable
  `workflow_runs.timeout_cleanup_work_deadline_at` with no backfill and builds
  its index without blocking writes. Poll-mode and service starts refuse this
  option until their capability admission is implemented (server#1201). Bridge
  workers cannot claim an opted-in embedded run. Service mode follows separately.

## 2.28.0 - 2026-11-13

**Behavior change:** an un-awaited `select()` loser is cancelled when the run
closes. To keep a service-mode activity running, schedule it with
`cancellation_policy: abandon`; its outcome is then never recorded. See the
first entry below. No migration.

- A run no longer leaves work running after its own code closes it (#1117).
  Before, an activity or timer the workflow never awaited, such as a
  `select()` loser, stayed open when the run completed, failed or continued as
  new. The activity still executed, and its `ActivityStarted` and outcome were
  appended to the closed run's history after the terminal event. Now the close
  records `ActivityCancelled` for each open activity and `TimerCancelled` for
  each pending timer in the same transaction, before `WorkflowCompleted`,
  `WorkflowFailed` or `WorkflowContinuedAsNew`, and cancels their tasks. Such a
  cancellation carries `cancellation_reason: run_closed`, and replay ignores
  it, because the workflow never observed it: it never changes a selection
  winner or a parallel group's failure. A
  late claim, heartbeat or outcome for such an activity is refused with
  `run_closed`, including one left behind by a run closed before upgrading.
  Nothing is appended to the closed run. Exception: a service-mode activity
  scheduled with `cancellation_policy: abandon` keeps running. The terminal
  event lists it under the new `abandoned_activities` payload key, and its
  heartbeats and outcome are accepted but not appended. Cancel, terminate,
  timeout and cooperative cancellation already settled open work this way.
  Children keep their parent-close policy and continue-as-new hand-over.
  Behavior change: if a workflow needs a loser's effect, await the loser
  before returning.

- Replaying a workflow that repeats the same message-stream send no longer
  lists every earlier matching row again for each send (#1014). The exactly
  matched candidates for a send identity are now loaded once per execution
  and extended with the rows that execution writes, so N identical sends in
  one replay cost one candidate query instead of N, and O(N) rows instead of
  O(N²). Matching is unchanged: exact field comparison, send order and the
  redrive chain.

- An expired open activity row of a closed run is now closed instead of being
  selected and skipped on every timeout sweep (#1117 follow-up). An activity
  that its run's close left running under the abandon policy is marked failed
  when its own `schedule_to_close` deadline passes without an outcome. Any
  other open row of a closed run, such as one left by a close before
  upgrading, is cancelled. Its task is cancelled and nothing is appended to
  the closed run's history. Before, such rows stayed open and could fill the
  sweep's batch limit.

## 2.27.0 - 2026-11-13

- Closing a child no longer reads every sibling's history under the parent
  lock (#892). Before, each child close loaded the full history of every
  other child of its parent to record the outcome, check a parallel group
  and re-project the parent, so a wide fan-out did quadratic history reads
  while blocking the parent's own workflow task. Now a close reads the
  closing child's history and the parent's own history and links. A
  sibling's outcome comes from the parent's resolution event, or from the
  sibling's terminal event when none is recorded yet. Measured with 50
  children, closing all of them read 96,052 sibling history rows on the
  embedded path and 26,460 through the service-mode bridge for a parallel
  group; both now read none, and history queries per close no longer grow
  with the number of children. Outcomes, wake-ups and projections are
  unchanged.

- A child close now costs the parent the same number of queries however many
  siblings it has (#892). The parent re-projection reuses the child runs it
  batch-loads with its child links instead of fetching each sibling again for
  every wait, lineage and summary lookup, and it reads the parent's lineage
  rows in one query. Embedded, queries per close went from 218, 372 and 790
  at 2, 10 and 30 children to 172, 158 and 156. Through the service-mode
  bridge (parallel group) they went from 153, 333 and 755 to 130, 141 and 143.
  The close that completes a parallel group still records every member's
  resolution once. Projections are unchanged.

- Embedded task repair and execution now stay inside the app's namespaces
  (#889). Before, apps sharing one database with the same connection and
  queue names could repair, redispatch, time out and run each other's tasks
  with the wrong app's code and credentials. The watchdog (including the
  queue worker's wake and `workflow:v2:repair-pass`), existing and missing
  task repair, run deadlines, activity timeouts, service-call deadline,
  dispatch and linked-call repair, `workflow:v2:schedule-tick`, and the
  `RunWorkflowTask`,
  `RunActivityTask`, `RunTimerTask` and `RunServiceCall` jobs now act only on
  the new `workflows.v2.namespaces` allowlist (`DW_V2_NAMESPACES`). Unset, it
  is exactly `workflows.v2.namespace`, so a single-namespace app behaves as
  before. A comma-separated list serves each listed namespace (`default` is
  the unscoped one); an empty value serves none; `*` is refused. A queued
  job outside the allowlist, including one queued before upgrading, is
  skipped before any effect and logged as a warning. Serving every namespace
  needs the separate `workflows.v2.all_namespaces` (`DW_V2_ALL_NAMESPACES`)
  opt-in. Upgrade note: an embedded app that starts, schedules or signals
  workflows in namespaces other than `DW_V2_NAMESPACE` must list them in
  `DW_V2_NAMESPACES`, or their tasks and schedules wait for an app that
  serves them. A process that repairs or ticks every namespace, such as the
  standalone server, must set `DW_V2_ALL_NAMESPACES=true`. The allowlist is an
  execution-safety boundary, so one app never runs another app's work with the
  wrong code or credentials. It is not tenant isolation: apps that share one
  database can still read each other's rows.
- Work refused by the namespace allowlist is now counted, so a misconfigured
  upgrade no longer stalls tasks with a log line as the only sign (#1112).
  The operator metrics snapshot has a new `namespace_scope_refusals` block
  with the app's allowlist and refusals per namespace and path over the last
  15 minutes: `job` counts each skipped queued job, and `repair`, `timeout`
  and `schedule_tick` count repair candidates, expired activity and run
  deadlines, and due schedules that a pass left in a namespace outside the
  allowlist after they had waited more than two minutes (sampled at most once
  a minute per path). The new advisory `namespace_scope_refusals` health check
  warns while refusals keep arriving and clears 15 minutes after the last one.
  The skipped-job warning is now logged once per namespace a minute; the rest
  are counted. Counts live in the default cache store in one-minute buckets,
  with no table and no migration. Use a shared store (Redis, database or
  Memcached) so the web process that serves the health snapshot sees the
  workers' counts.

## 2.26.0 - 2026-11-12

- A child handed to a continued run now gets its retries (#1105). Before, a
  child that failed after its parent continued as new was never retried,
  because the retry needed the parent to be waiting. It is now retried under
  the retry policy the parent recorded, with the same child call identity and
  attempt count, while a run of the chain still supervises it. The retry run's
  `WorkflowStarted` names the original parent run, and the supervision moves to
  the retry run, so the chain's final run applies the parent-close policy to it.
  Nothing is appended to the closed predecessor. Once the attempts are spent,
  the failure is non-retryable, or the chain has closed, the failure is final.
  Embedded and service-mode runs behave the same. No migration.
- Failed-run redrive now reuses a completed child workflow call (#864).
  Before, any child workflow in the failed run's history refused redrive with
  `unsupported_history`. The successor records the child's
  `ChildWorkflowScheduled`, `ChildRunStarted` and `ChildRunCompleted` events
  with `reused_from_run_id` and `reused_recorded_at`, keeps the original child
  instance, run, link id and call id, and does not start the child again or
  write a child link of its own. Executor, query and fiber replay resume the
  call with the recorded output at the time it first closed, so `now()` after
  the call matches the failed run. Redrive chains carry the reuse forward.
  Child steps that replay would not reproduce are refused before any write,
  with new reasons: `unsupported_open_child`, `unsupported_child_outcome`
  (failed, cancelled or terminated children), `unsupported_child_workflow`
  (parallel, scoped, retried or continued children, or mismatched call
  identity), `external_child_result_not_supported` and
  `unavailable_child_result`. Runs a service worker started stay refused with
  `service_redrive_history_not_qualified` when they would reuse a child step.
  Runs that are themselves children are still refused. No migration.
  Rollback: earlier releases ignore `reused_recorded_at` on child events, so
  replaying such a successor there can give a different workflow time, and
  deadlines derived from it, after the reused call; they also refuse to
  redrive it again (`unsupported_history`). Once a successor with a reused
  child call exists, keep workers on this release or later, or confirm that
  no such history can replay before rolling back.
- Workflow code can read the history budget replay-safely with
  `historyBudgetAtStep()` and `shouldContinueAsNewAtStep()` (#859). They count
  only the history recorded before the current workflow step, so the same line
  returns the same value on every replay, and a loop that checks the budget
  each iteration continues as new once, at the first iteration that crosses the
  threshold. The existing `historyLength()`, `historySize()`, `historyFanOut()`,
  `shouldContinueAsNew()` and `historyBudgetPressure()` still read the whole
  current history and are unchanged, so runs that already decided with them
  keep replaying. Inside a loop they can answer differently on replay; use the
  new methods there.

- Upgrade migrations that fill in existing rows no longer lock the table for
  the whole backfill on PostgreSQL (#871). The output codec (`2026_07_13`),
  local activity exception (`2026_09_24`), schedule occurrence (`2026_09_29`)
  and service call deadline (`2026_10_11`) migrations now commit the new
  column first, with a one-second lock wait, and then fill rows in small
  batches that each commit on their own. The `2026_09_29` and `2026_10_11`
  indexes are now built after the backfill without blocking writes
  (`CREATE INDEX CONCURRENTLY` on PostgreSQL, `LOCK=NONE` on MySQL and
  MariaDB). An interrupted run keeps the batches it finished and can be run
  again. The finished schema and data are the same as before, and installs
  that already ran these migrations are not affected.
- A parent that continues as new while children it started are still open
  now hands them to the continued run (#868). Embedded and service-mode
  continue-as-new record one `workflow_child_supervisions` row per open child,
  in the same transaction. The child link, child call, sequence, deadlines and
  parent-close policy stay as recorded. When the chain's final run completes,
  fails, is cancelled, is terminated or times out, it applies each child's
  `request_cancel`, `request_cancellation` or `terminate` policy and records
  the outcome in its own history. `abandon` stays `abandon`. Before, these
  children kept running and their policy was never applied. Nothing is
  appended to the closed predecessor, and the continued run gets no handle on
  the children. The unused `ChildCallService::transferChildCallsToContinuedRun()`
  is removed: it re-keyed child calls to the continued run, where their
  sequences could collide with its own children. Run the new migration.
- An activity outcome no longer locks the attempt row after it holds the task,
  execution and run (#1000). It closes the attempt with one guarded conditional
  update instead. Lease renewal locks the attempt first, so the old order could
  deadlock a renewal against an outcome for the same attempt on PostgreSQL and
  MySQL, mainly on the stale, cancelled and terminated paths.

## 2.25.0 - 2026-11-12

- Changing a schedule's action now drops fires buffered under the old
  action (#876). Each dropped fire is recorded as a `ScheduleTriggerSkipped`
  event with reason `action_changed` and its `occurrence_time`. Updates that
  leave the action unchanged keep the buffer.
- Memo and search attribute limits now apply the same way on start, embedded
  upserts and worker upserts (#853). Going over a fixed cap (memo value
  10,240 bytes, 100 memo keys, 65,536 memo bytes, search attribute string
  2,048 or keyword 255 characters, 100 attributes, 65,536 attribute bytes)
  raises `StructuralLimitExceededException` instead of
  `InvalidArgumentException`. Its new `limitName` says which limit was hit,
  and a failed run records it as `structural_limit_name`. An embedded upsert
  over a fixed cap now fails the run as a non-retryable structural-limit
  failure, as the configured limits already did.
- Start now applies the configured `memo_size_bytes` and
  `search_attribute_size_bytes` limits. A configured limit can tighten a
  fixed total but no longer appears to raise it. The `memo_size_bytes`
  default is now 65,536 (was 262,144, which the fixed 64 KiB total made
  unreachable). Zero still turns the configured limit off; the fixed caps
  stay. Upgrade note: code that caught `InvalidArgumentException` for these
  limits should catch `StructuralLimitExceededException`.

## 2.24.0 - 2026-11-11

- Activity heartbeats no longer grow run history with every heartbeat
  (#870). Each heartbeat still renews the lease, moves the heartbeat
  deadline and stores the latest progress on the attempt. An
  `ActivityHeartbeatRecorded` event is written only when progress changed
  since the last recorded event and at least max(heartbeat timeout, 60
  seconds) has passed. Heartbeats without progress write none. Progress that
  was not yet recorded is recorded once when the attempt closes. Run detail,
  Waterline and history export read the latest progress from the attempt.
  Liveness, timeouts and retries are unchanged, and older histories with one
  event per heartbeat replay and export as before. This is a behavior change:
  expect far fewer heartbeat events.
- A migration adds nullable `last_heartbeat_progress`,
  `last_heartbeat_progress_at` and `last_heartbeat_recorded_at` columns to
  `activity_attempts`. It is additive, with no backfill.
- Re-applying an unchanged schedule records nothing and keeps the next fire
  time and its jitter. `changed_fields` lists only fields whose stored value
  changed. Only a spec or jitter change moves the next fire, so
  metadata-only updates cannot skip a due occurrence (#876, #1089).
- Creating a schedule with a taken id, including a deleted or
  `max_runs_exhausted` schedule, raises
  `Workflow\V2\Exceptions\ScheduleAlreadyExistsException` instead of a raw
  database error, matching the Server's `409 schedule_already_exists`
  (#876, #1090).
- Operator metrics report open runs whose workflow task cannot replay
  (`runs.replay_blocked`, `oldest_replay_blocked_at`,
  `max_replay_blocked_age_ms`), and a new advisory `replay_blocked_runs`
  health check warns while any exist (#861, #1091).

## 2.23.2 - 2026-11-11

- Service-mode worker commands get the remaining size limits embedded
  workflows already apply: side-effect results, local activity arguments and
  results, and update results are checked against the payload size limit, and
  search attribute upserts against the search attribute size limit. An
  oversized value refuses the whole completion with the typed
  structural-limit failure (#865, #1086, #1087).
- No database migration, history format or dependency floor change.

## 2.23.1 - 2026-11-11

- A per-call activity `maxAttempts` below 1 is refused when a new activity or
  local activity is scheduled, and fails the run with a message that the
  budget counts the first attempt (#860). Previously it ran one attempt
  silently. For unlimited attempts, omit `maxAttempts` and set the activity
  class `$tries` to 0.
- Histories that recorded `maxAttempts: 0` under an earlier release keep
  replaying after an upgrade: replay accepts that recorded budget when the
  code still passes 0, and it means one attempt. Before this change such runs
  failed replay with a retry policy mismatch.
- Embedded saga `compensate()` runs under a cancellation shield, as the PHP
  and Python SDK sagas do. A cooperative cancellation request that arrives
  during compensation no longer skips the remaining compensations. The cleanup
  deadline, termination and run timeouts still apply (#872, #1077).
- An update accepted while the workflow task that completes the run is running
  gets one delivery turn before the run closes, as a signal does. Its handler
  runs and the caller gets its result instead of a closed-run failure. Updates
  the task could already see keep the previous behavior (#890, #1078).
- The embedded determinism scan flags `new DateTime()`, `new
  DateTimeImmutable()`, `new Carbon()` and `new CarbonImmutable()` when they
  read the current time, plus `today()`, `shuffle`, `array_rand` and
  `str_shuffle`. Fixed dates are not flagged (#888, #1080).
- Service-mode worker commands get the payload size limit embedded workflows
  already have: oversized activity, child or continue-as-new arguments and
  workflow results refuse the whole completion with the typed structural-limit
  failure (#865, #1083).
- No database migration, history format or dependency floor change.

## 2.23.0 - 2026-11-11

- Cap queue delays on Laravel Cloud's managed queues. Laravel Cloud's `cloud`
  queue driver wraps Amazon SQS, which rejects delays over 900 seconds, but
  the cap only applied to the `sqs` driver. On Laravel Cloud, timers,
  `awaitWithTimeout()` and delayed v2 tasks longer than 15 minutes failed with
  `DelaySeconds must be >= 0 and <= 900`. They now wait in steps of at most
  900 seconds, as on plain SQS (#1074, reported in #1072).
- Answer queries with what an update handler's stream receive took. A query
  replayed an update handler's `inbox()->receive()` at the wrong step and saw
  nothing (#1024). Live update handlers now record the message ids each
  receive returned on their `UpdateApplied` event, and replays return exactly
  those messages. Updates applied by earlier releases keep the previous
  lookup (#1076).
- Add experimental business transition contracts under
  `Workflow\V2\StateMachine` with native Saga integration (#1071, see
  `docs/business-state-machines.md`). The API is marked experimental and may
  change in a minor release.
- No database migration or dependency floor change. `UpdateApplied` history
  gains an optional `message_receives` field. Older runtimes ignore it.

## 2.22.0 - 2026-11-08

- Add an opt-in first-open wall deadline for condition-wait commands. With
  `deadline_policy: "occurrence"`, physical false-wake reopens retain the first
  timer's exact deadline and original timeout budget. Conflicting, timed-out
  or unprovable chains refuse with whole-completion rollback. Legacy chains
  first opened without the policy retain their existing per-open behavior;
  an anchored chain must keep its policy on every reopen.
- The embedded worker protocol advertises 1.22 and continues accepting 1.20
  requests. This package adds no database migration and keeps the existing
  PHP and Laravel support floors. PHP Server capability advertisement and an
  SDK release containing the opt-in support remain separate delivery steps.
- Rollback: after any workflow has used the occurrence deadline policy, do
  not continue its history on a pre-2.22 runtime. Older command normalizers
  can discard the new fields, so a false-wake reopen can mix a per-open wait
  into an anchored chain. A later upgrade does not repair that mixed history.
  Restore a backup taken before the upgrade if rolling back such histories.

## 2.21.1 - 2026-11-07

- Exclude the test tree from Composer distribution archives (#1064), avoiding
  the `tests/Fixtures` and `tests/fixtures` directory collision when vendor
  files are synchronized to a case-insensitive filesystem. Source checkouts
  retain the complete test corpus. Runtime code, history, schema, public APIs
  and the 2.21.0 platform support floors are unchanged.

## 2.21.0 - 2026-11-07

- Workflow 2.21.0 requires PHP `^8.2` and patched Laravel
  `^12.69.0|^13.30.0`. Laravel 10/11 and PHP 8.1 are no longer supported.
  Upgrade the application platform first while preserving its existing
  Workflow 2.20.x lock, then update Workflow. The stable-v1 authority and
  ownership of existing durable history are unchanged.
- The embedded source/published upgrade matrices now cover Laravel 12/13
  on their supported PHP 8.2–8.4 intersection. Laravel 13 still requires
  PHP 8.3 or newer. Removed-framework constraints refuse before installation;
  retired-platform advisory exceptions are not carried into 2.21.

- Add namespace-bound schedule visibility predicates for `ScheduleId`,
  `Status`, `WorkflowType`, `TaskQueue` and `Note`. Equality and `AND` use
  bound values; unsupported fields and operators return typed refusals.
- Add guarded prepared local-activity retry contract 2 helpers and an optional
  task-bridge interface. Preparation snapshots, report validation, retry
  decisions and lost-claim recovery retain original authority and fences.
  The default bridge does not implement the new role; these helpers do not
  enable a runtime capability or change existing v1 bridges.
- Include the parallel activity continuation prevention fix described under
  2.20.5 below. Existing stranded runs still require separate recovery.

## 2.20.5 - 2026-11-07

- Prevent a successful parallel activity group from missing its continuation
  under MySQL repeatable read (#1052). The terminal activity handler now reads
  current history while holding the parent-run lock, so a sibling completion
  committed after its earlier snapshot is visible. Named storage and nested
  all groups follow the same path; duplicate completion still wakes once.
- This is a prevention change with no schema, payload or public API change.
  It does not automatically resume runs already waiting without a continuation.
  Rolling back restores the missed-wake defect.

## 2.20.4 - 2026-11-05

- Fix an embedded MySQL/InnoDB REPEATABLE READ deadlock between
  continue-as-new and unrelated signal admission. Caller-owned transactions
  retain their original read views, pending default/named signal handoffs
  remain durable, and user workflow code is not automatically replayed to
  recover from the deadlock.

## 2.20.3 - 2026-11-04

- Rollback, embedded Workflow package: 2.20.3 changes no schema, history
  event or public API. Rolling back restores the shared-cursor, empty
  receive replay and fake task-drain precision bugs. A kept stream now
  records receives at the current step. Earlier versions look them up at the opening step and can replay
  those runs differently after rollback.
- A message on one stream stays receivable until it is consumed or expires
  (#1011). The run's message cursor is shared by signals, updates and every
  stream, so a signal, an update or a receive on another stream used to move
  it past older pending messages, and `inbox()->receive()`, `peek()`,
  `pendingCount()` and `hasPending()` no longer returned them. They now read
  every pending message of the stream, in sequence order.
  - After upgrading, workflow code can receive messages that earlier
    releases hid. They are the stream's oldest pending messages, so a
    workflow that receives in a loop gets them first.
  - A receive replayed from history still returns what that step consumed,
    and a replayed receive whose original call found nothing returns nothing
    again. It no longer reads the stream at a step history has passed. So a
    run where an earlier release's cursor hid a message keeps the result it
    had, and receives the message at a later step instead of consuming it at
    the old one. An update handler that runs for the first time still
    receives, even when its update is recorded at an earlier step than the
    one the run waits at.
  - A receive through a stream kept in a property records its consumption
    at the step the code is at, not the step the stream was opened at, so
    its replay matches too. Consumptions that earlier releases recorded at
    the opened step still replay.

- `WorkflowStub::runReadyTasks()` now drains fake-workflow tasks at their
  exact due time, including fractional seconds (#1026). It compares the
  sampled clock using the configured task model's persisted precision.
  SQLite previously skipped tasks due at that instant because it compared
  their fractional timestamps with a whole-second query value. Future
  fractional deadlines remain pending until they are due.
- Known limitation (#1024): a query can return nothing for a message an
  update handler received, because the query replays the handler at the
  wrong step. The query changes no message rows. The workflow's own replay
  and completed output still return what the handler received. This issue
  predates 2.20.3 and remains open.

## 2.20.2 - 2026-11-04

- Rollback, embedded Workflow package: 2.20.2 changes no schema, history
  event or API. Rolling back to 2.20.1 or earlier brings back the stranded
  pending stream messages at redrive (#1013) and at continue-as-new (#1018).
  Messages that 2.20.2 already moved to a successor or continued run stay
  there.
- Continue-as-new moves the run's pending inbound stream messages to the
  continued run (#1018). Before, only the message cursor carried over, so a
  stream message still pending when a run continued as new stayed on the
  closed run and was never received. This covers embedded execution and
  external workers that complete a task with `continue_as_new`. Signals were
  not affected.

- A redrive moves the failed run's pending inbound messages to the successor
  (#1013). Before, only the message cursor carried over, so a message that
  arrived while the run was failed stayed on the failed run and the successor
  never received it. A replayed receive whose original call found nothing can
  now return one of the moved messages.
- Known limitation, not fixed in 2.20.2 (#1011): stream receives still start
  after the run's shared message cursor, which signals and receives on other
  streams also advance. A stream message that is still pending behind that
  cursor stays hidden from `inbox()->receive()` and `receiveOne()`. A replayed
  receive whose original call found nothing can also read the stream again
  and return a message. The fix (#1017) follows in 2.20.3.

## 2.20.1 - 2026-11-04

- Rollback, embedded Workflow package: 2.20.1 changes no schema, history
  event or API. Rolling back to 2.20.0 or earlier brings back the duplicate
  sends and replay receives that #1010 describes. Rows written while 2.20.1
  ran stay correct, and a run that 2.20.0 later replays can add duplicates
  again. Service-mode SDK workers don't use `MessageStream`.
- Message stream calls in workflow code act once instead of on every replay
  (#1010). Workflow code runs again on each later workflow task, on every
  query and in a redrive successor's reused prefix.
  - `outbox()->sendReference()` used to write a new outbound and inbound
    message each time. A replay now returns the row the original send wrote,
    including a row written by the run a successor was redriven from.
  - `inbox()->receive()` and `receiveOne()` now return the messages that call
    consumed at that step, by this run or by the run it was redriven from.
    Before, a replay could return different messages or none, and could
    consume a newer message under the earlier step.
  - A query, a replay check or a redrive definition check never writes or
    consumes a message. A stream kept across a replayed update handler checks
    this on every call, so later sends still write.
  - Rows that earlier releases duplicated are not removed. Reads don't
    deduplicate them, and no migration can tell a replay duplicate from an
    intended identical send. A consumer that read the stream on an affected
    release should deduplicate by `idempotency_key`. A run with such rows
    matches its sends to the earliest rows and writes only new sends.
  - Messages that a replay or query on an affected release (2.20.0 and
    earlier) consumed under the wrong step stay consumed, and workflow code
    won't receive them again. Check streams that workflow code receives from
    for consumed messages the workflow never handled.
  - A replayed receive whose original call found no message still reads the
    stream again, because nothing records an empty receive. It can return a
    newer message, or a message that a later call consumed at the same step,
    for example through a stream kept in a property, which keeps the step it
    was opened at. Receive only after a signal or condition says a message is
    there, and send that signal before the message: a signal moves the run's
    shared message cursor past older pending messages (#1011).
  - A send is matched by its exact content (channel, stream, target,
    reference, correlation id and idempotency key), whatever the column
    collation. An identical row that code outside
    the workflow wrote for the same run can satisfy it, so give workflow sends
    their own idempotency key.

## 2.20.0 - 2026-11-03

- Rollback, embedded Workflow package:
  - The 2.19.0 floor still applies. Releases before 2.19.0 ignore
    `reused_recorded_at` on reused signal, memo and search-attribute events,
    so a redrive successor with those events can replay with a different
    workflow time and deadlines there. Keep 2.19.0 or later workers, or
    confirm no such history can replay, before rolling back below 2.19.0.
  - From 2.20.0 to 2.19.0: 2.20.0 changes no replay code. 2.19.0 replays,
    imports and resumes a 2.20.0 successor that reused an applied update or a
    chained signal wait with the same workflow state, output and next
    command.
  - 2.19.0 refuses to redrive a failed run that 2.20.0 redrove with a reused
    update (`unsupported_history`) or a reused signal wait
    (`mismatched_signal_receipt`) before any write. A request for a run that
    already has its successor returns that successor.
  - On 2.19.0, run detail shows redrive links as diagnostic fallbacks.
  - Service-mode SDK workers stay behind the
    `service_redrive_history_not_qualified` fence.
- Redrive a failed run whose workflow applied an update before the failure.
  The successor records the update's acceptance, application and completion
  with `reused_from_run_id` and `reused_recorded_at`, ahead of the step it
  ran before, and replay runs the update handler again from its recorded
  arguments so the workflow state matches. The update is not delivered again.
  A rejected update, and one the failure closed before it ran, are not reused.
  Only three update shapes are accepted, in history order: a rejection, an
  acceptance closed by the failure, or an acceptance, application and
  successful completion with identical arguments. Every event in each shape
  must name the same update, command and handler and belong to the source run.
  An acceptance closed by the failure must record a terminal reason and a
  failure, with no result and no step. An update the source
  accepted but never resolved is refused with `unsupported_pending_update`,
  any other shape or binding with `mismatched_update_history` or
  `incomplete_update_step`, an externally stored update payload with
  `external_update_payload_not_supported`, and an inline payload that can't be
  decoded with `unavailable_update_payload`. A run a service worker started
  that applied an update is refused with `service_redrive_history_not_qualified`
  (#864).
- Redrive a successor that itself reused signal waits or updates. A reused
  payload keeps the run that first recorded it, and `reused_from_run_id` names
  the immediate source, so a chain of redrives reuses each step once.
  `reused_from_run_id` and `reused_recorded_at` come as a pair on every reused
  update and signal event. A reused event keeps its original time, and a
  history where one key is missing, blank or names another source is refused.
  Any `external_storage` key on a signal payload counts as external, even a
  null one. Before,
  redriving such a successor was refused with `mismatched_signal_receipt`
  (#864).
- Run detail and describe report a redrive link as typed lineage. The
  successor's `parents` and the failed run's `continuedWorkflows` show
  `link_type: redrive` with `history_authority: typed_history` and
  `diagnostic_only: false`, because the successor's `WorkflowStarted` records
  `recovery_kind: redrive`, `continued_from_run_id` and `workflow_link_id`.
  Both sides take the step (`resume_step_sequence`) and time from that event,
  not from the link row. The start must name the successor's own run and
  instance, an earlier run of the same instance as the source, a nonblank link
  id and a positive resume step, and exactly one redrive link row must join
  that source to the successor under that link id. Before, both sides showed
  a diagnostic-only fallback. A link the successor's history does not confirm stays a single
  diagnostic fallback (#864).

## 2.19.0 - 2026-11-03

- Rollback: releases before 2.19.0 ignore `reused_recorded_at` on the signal,
  memo and search-attribute events that a 2.19.0 redrive successor reuses.
  Replaying such a successor on an older release can change its workflow time
  and the deadlines derived from it. Once a 2.19.0 redrive successor with
  reused signal waits or upserts exists, keep 2.19.0 or later workers, or
  confirm that no such history can replay before rolling back (#864).
- Redrive a failed run whose workflow code upserted memos or search
  attributes. Before, any `MemoUpserted` or `SearchAttributesUpserted` event
  refused redrive with `unsupported_history`. The successor reuses those
  events in step order, carrying `reused_from_run_id` and
  `reused_recorded_at`, and replay uses the original time. Memo and search
  attribute rows now carry to the successor unchanged, keeping the step that
  wrote them and whether they were inherited, so a successor that fails again
  can be redriven again. A row written by workflow code with no matching
  reused upsert step is still refused with `unsupported_visibility_metadata`
  (#864).
- Redrive a failed run whose completed steps include a signal wait
  (`await('name')`) that received and applied its signal. The successor
  records the wait, receipt and application with `reused_from_run_id` and
  `reused_recorded_at`, and replay uses the original time, so the workflow
  gets the same value without a new signal. Redrive also carries the source's
  message cursor, so a consumed message is not delivered again. A wait with a
  timeout, cancellation scope or parallel group is refused with
  `unsupported_signal_wait`, a signal the source received but never applied
  with `unsupported_pending_signal`, and a wait without its receipt and
  application with `incomplete_signal_step`. A receipt that names another
  run, instance, command or wait is refused with `mismatched_signal_receipt`,
  and an externally stored signal payload with
  `external_signal_payload_not_supported`. Updates in the completed prefix
  are still refused (#864).
- Redrive a run that failed in workflow code or on a structural limit.
  `WorkflowFailed` now records `failed_step_sequence` with `failed_step_kind`
  `workflow_code` or `structural_limit`, the step the code never took. The
  successor reuses every earlier step and resumes there. A workflow-code
  failure on the same definition is refused with
  `workflow_code_failure_requires_corrected_definition`, because the same code
  would throw again. Pass `allow_definition_change: true` to redrive onto a
  corrected definition: inside the redrive transaction, the new code replays
  the reused prefix in memory, and redrive refuses with
  `definition_incompatible_with_prefix` when it can't (with
  `definition_mismatch.sequence` and `.kind` naming the first step it
  disagrees with), or with
  `definition_check_over_budget` when the prefix exceeds the history budget,
  before writing anything. An accepted change records
  `definition_changed_from` on the successor's `WorkflowStarted`, and run
  detail (`workflow_definition_changed_from`) and describe
  (`run.definition_changed_from`) show it. A later redrive on the same
  definition records no change. A run started by a service worker is refused
  with `definition_change_requires_replay_check`, even when a local class is
  registered for its type later. Failures
  recorded by earlier releases have no boundary and still refuse with
  `unrecorded_failure_boundary` (#864).
- Redrive refuses a run a service worker started when its completed prefix
  has a memo upsert, search-attribute upsert or signal wait to reuse, with
  `service_redrive_history_not_qualified`, before writing anything. Embedded
  runs, and service runs whose prefix holds only activities, side effects,
  version markers and fired timers, redrive as before. The refusal lifts only
  through a replay-capability check once SDK replay of those reused events
  ships (durable-workflow/sdk-python#235, durable-workflow/sdk-php#244,
  durable-workflow/sdk-rust#172) (#864).

## 2.18.0 - 2026-11-03

- Redrive a failed run that has start-time memos or search attributes. Before,
  any memo or search attribute row refused redrive with
  `unsupported_visibility_metadata`. The successor now inherits the start
  values, as continue-as-new does. A value upserted by workflow code is still
  refused until redrive replays that part of the history (#864).
- Run detail and describe report whether redrive would accept a run.
  `can_redrive` and `redrive_blocked_reason` come from the same decision
  `redrive()` applies, so the action an operator sees matches what redrive
  does. Runs that are not failed report `run_not_failed`, and a source that
  was already redriven reports `already_redriven` (#864).
- Redrive a failed run whose completed steps include side effects or version
  markers. Before, any `SideEffectRecorded` or `VersionMarkerRecorded` event
  refused redrive with `unsupported_history`. The successor reuses them in step
  order without running the side effect again. Like reused activity
  completions, they carry `reused_from_run_id` and `reused_recorded_at`, and
  replay uses `reused_recorded_at` so workflow time after the step matches the
  source run. New refusal reasons: `invalid_step_sequence`,
  `duplicate_step_sequence` and `external_side_effect_result_not_supported`
  (#864).
- Redrive a failed run whose completed steps include a fired `timer()`. The
  successor records the timer as already fired, under a new timer id, with no
  timer row or task, so it does not wait again. `TimerFired` carries
  `reused_from_run_id` and `reused_recorded_at`, and replay uses the original
  fire time. Condition and signal timeouts, cancellation cleanup timers and
  timers in a cancellation scope or parallel group are refused with
  `unsupported_timer`. A timer step without both its scheduled and fired events
  is refused with `incomplete_timer_step` (#864).
- Retain keyed signal admissions and compare canonical typed intent for keyed
  signals and updates. Conflicting key reuse refuses without durable writes;
  undecodable legacy receipts remain explicitly unverified. This strengthens
  the earlier same-key update receipt contract (#877).
- Require valid UTF-8 caller keys of 1..255 code points before admission.
  Explicit empty or non-string keys refuse; null clears an inherited key.
  Apply the nullable `intent_digest` migration, drain and upgrade all embedded
  database writers together, and allocate new keys for different operations.
  Native Server and published SDK/CLI qualification remain separate.

- V2 payloads reject an Eloquent model, `ModelIdentifier` or Eloquent
  collection with an `unsupported_value_type` message that names the model
  class and says to pass the key and load the model inside the activity.
  Before, the message named Laravel's internal `ModelIdentifier` type. The
  Avro argument path no longer runs `serializeModels`, and failure and
  throwable normalization are unchanged (#997).
- Record a bounded diagnostic when a late activity outcome is refused. The
  first refused completion or failure from an expired or superseded attempt
  appends one `ActivityOutcomeRejected` event with the attempt identity, lease
  owner, attempted outcome, refusal reason and observation time. It is
  payload-free: the result, failure, stack and request snapshot are never
  stored. It grants no business outcome, renews no lease and changes no retry.
  Readers have understood this event since 2.13.0, so after an upgraded
  release writes one, do not roll back below 2.13.0 (#878, #901).

## 2.17.1 - 2026-11-03

- Replay verification accepts a failed run that replays to its recorded
  failure. Before, `ReplayDiff` and `workflow:v2:replay-verify` reported
  `replay_error` for any run that failed because workflow code threw, even when
  replay reproduced the recorded `WorkflowFailed` exception. A matching
  exception class and message now report `replayed` with
  `replay.recorded_failure`. A different exception stays `replay_error` and
  names the recorded failure in `error.recorded_failure` (#983).

## 2.17.0 - 2026-11-02

- Do not export a retention-pruned run as complete history. After
  `WorkflowRunRetentionCleanup::pruneRun()`, `HistoryExport::forRun()` sets
  `history_complete` to `false`, adds `workflow.details_state: "pruned"` and
  `workflow.details_pruned_at`, and appends `:details-pruned:{time}` to the
  `dedupe_key`, so the post-prune export can't be mistaken for the full export
  taken before cleanup. Every bundle now carries `details_state`; runs that
  were never pruned keep their existing dedupe key. Bundle verification reports
  pruned bundles as `history.details_pruned`, so replay verification fails
  them, and history import rejects them (#856).
- Drop Laravel 9 from the supported `laravel/framework` constraint, which is
  now `^10.50.4|^11.56.1|^12.69.0|^13.30.0`. Laravel 9 has no fix for the
  TokenGuard advisory GHSA-f9x3-7vq9-cph6. Composer keeps Laravel 9
  applications on Workflow 2.16.x, so updating does not break them; to move
  past 2.16.x, upgrade the framework to a patched Laravel 12 or 13 release
  first. The embedded upgrade contract no longer lists Laravel 9 cells, and the
  dependency security policy records the removal. The dependency security audit
  now requires Composer to refuse this package with Laravel 9 (#922).

## 2.16.0 - 2026-11-02

- Let embedded operators size schedule evaluation passes.
  `workflow:v2:schedule-tick` accepts `--limit=N`, and the new
  `workflows.v2.schedules.tick_limit` config (`DW_V2_SCHEDULE_TICK_LIMIT`)
  sets the default. The default stays 100, so existing behavior is
  unchanged. Each pass starts at most that many due schedules and drains at
  most that many buffered actions, shared round-robin across namespaces;
  the rest fire on later passes. A limit that is not a positive integer is
  refused, and the command fails without evaluating schedules (#875).

## 2.15.15 - 2026-11-02

- Start an independent workflow instance for each namespace when schedules
  in different namespaces share a schedule ID. New scheduled starts use
  instance IDs of the form
  `schedule.ns:{namespace hash}:{length}:{schedule_id}:{suffix}`, so the same
  occurrence no longer collides across namespaces or after the configured
  default namespace changes. Instances started under the earlier
  `schedule:{schedule_id}:{suffix}` form stay readable, and occurrences that
  already fired stay deduplicated through schedule history (#874).

## 2.15.14 - 2026-11-02

- Persist `StartOptions::withPriority()` and `withFairness()` on runs started
  through the embedded `WorkflowStub`; they were accepted but not stored, so
  embedded runs always used the default priority and fairness class (#971).
- Give every task of a run the run's priority, fairness key and fairness
  weight. Workflow tasks that resume a run after a timer, signal, update,
  child or activity outcome, timer tasks, and repair tasks no longer fall
  back to the default priority and the shared fairness class. Activity
  retries keep per-call overrides. Values a task creator sets explicitly
  still win. Embedded continue-as-new and child retry runs keep the
  predecessor run's priority and fairness, as control-plane continue-as-new
  already did (#971).

## 2.15.13 - 2026-11-02

- Fire a cron schedule once for a wall-clock time that repeats when daylight
  saving time ends. A daily `30 1 * * *` schedule in `America/New_York` no
  longer starts a second run at 01:30 EST on the day clocks go back, and
  `30 2 * * *` no longer fires early inside the repeated hour. A time skipped
  when clocks go forward still fires once, moved forward by the gap (02:30
  runs at 03:30). Schedules whose hour field selects every hour (`*`,
  `0-23` or a step of one) keep running on elapsed time, so hourly and
  sub-hourly schedules fire through both passes of the repeated hour (#882).

## 2.15.11 - 2026-11-01

- Keep embedded history imports and their lineage within the target namespace.
  Reject copied references to another workflow's rows and restore missing
  lineage edges only from matching stored relationship evidence. Preserve
  valid imports in either endpoint order and idempotent repeat imports (#928).
- Check ownership when reading or operating on previously imported commands,
  failures, activity attempts and timers, including message completion,
  recovery and retention cleanup (durable-workflow/server#765).
- Keep current-run resolution, pointer updates and detail views scoped when an
  older import attached runs from different namespaces to the same instance.
  Preserve valid same-namespace message handoffs and navigation (#928).
- Require exact run or unbound-instance namespace ownership before pruning
  messages with copied requested or resolved run references (#928).
- Keep foreign instance metadata out of legacy run exports, lists and summary
  projections. Advance the local summary cache schema and keep namespace-scoped
  projection rebuilds idempotent without rewriting foreign durable rows (#928).
- Run history imports and lineage repairs on the configured storage connection.
  PostgreSQL write imports require their own serializable transaction and
  retry serialization conflicts up to three complete attempts. Preview imports
  remain available within a caller transaction (#928).
- Preserve bounded SQLite history sizes for NUL-bearing JSON strings and keys
  on older JSON1 versions, without hydrating history or rewriting payloads (#968).

## 2.15.10 - 2026-10-31

- Create a follow-up workflow task when a child resolves after the parent
  worker has claimed its history and that worker completes into another wait.
  Preserve task coalescing, terminal parents and parallel child barriers so
  the result cannot remain parked behind an unrelated timer or signal (#962).
- Limit Laravel 9 support to existing lockfiles that need to upgrade or drain.
  Keep the documented TokenGuard exposure restriction and the next migration
  review date in the shipped dependency security policy (#922).

## 2.15.9 - 2026-10-31

- Keep engine transactions, task claims and deferred callbacks on the workflow
  storage connection selected by `DW_STORAGE_CONNECTION` or custom V2 models.
  Hold claim locks until commit and roll back failed starts without partial
  durable state. Lifecycle events wait for the outer transaction to commit and
  retain their execution trace context (#893).

- Require Laravel 10.50.4 or later on the Laravel 10 line so TokenGuard rejects
  non-string tokens in both user lookup and credential validation. Laravel 9
  retains the documented upgrade or drain requirement (#922).
- Back off between SQLite host-query preparation and dispatch-claim retries
  after a proven root rollback, so a brief competing writer does not exhaust
  all attempts immediately. Preserve caller-owned transactions and durable
  query claim fencing (#947).

## 2.14.1 - 2026-10-26

- Enforce pending activity, timer and child-workflow limits on external-worker
  task completions before any command writes. An over-limit batch now rolls
  back completely and retains the workflow task lease without durable changes
  (#933).

## 2.14.0 - 2026-10-26

- Require a trusted host authorizer for workflow service operations. Refuse
  worker-supplied principal overrides, foreign caller namespaces and service
  call IDs or idempotency keys owned by another run, instance or operation.
  Preserve explicit embedded null namespaces instead of using ambient config.
- Service-operation hosts must bind `WorkflowServiceOperationAuthorizer` before
  upgrading. The default refuses service operations; explicitly trusted embedded
  hosts can opt into `RunServiceOperationAuthorizer` for same-namespace calls
  with an unprivileged workflow principal. Authenticated servers supply their
  own current principal and authorize requested target scope.
- Recheck current service-call replay policy without reserving fresh admission
  capacity, including cached checkpoint receipts. Custom policies must provide
  the pure `authorizeReplay` hook.
- Serialize keyed service-call creation with a unique canonical scope digest.
  Reauthorize the actual locked row after a concurrent insert or uniqueness
  conflict. Apply the nullable schema expansion and drain older writers before
  relying on concurrent deduplication.
- Move service-operation execution, synchronous waits and retry backoff outside
  the workflow completion transaction. Persist a durable dispatch before commit,
  enqueue it only after commit, cap synchronous waits at 60 seconds and repair
  lost or expired claims while preserving one opaque effect identity across
  crash redelivery.
- Bound `record_local_activity` timing fields (`duration_ms`, heartbeat
  `elapsed_ms`, `backoff_seconds`) and refuse a report whose walk back from
  completion leaves the storable timestamp range (#930).
- Meter each workflow task completion with hard structural limits on history
  events, history bytes, metered rows and externalized payload bytes
  (`history_transaction_size`, `history_transaction_bytes`,
  `history_transaction_rows`, `external_payload_transaction_bytes`). Metered
  rows are history events and local activity records for now; other command
  writes are not counted yet. A refusal rolls the whole completion back and
  keeps the task's lease. A run's deadline close is never metered. The limits
  appear in the structural limit snapshot.
- Dispatch lifecycle events from completions only after the completion
  commits, so a refused or retried completion dispatches none.
- Add the optional `PredictableExternalPayloadStorageDriver` interface. A
  driver that implements it has payloads staged and stored only after the
  completion passes every limit. The built-in local driver implements it, and
  now stores objects atomically after checking their length and hash. Other
  drivers, including the filesystem driver, keep storing payloads as before.

## 2.13.1 - 2026-10-24

- Cancel the live task when a retried activity receives cooperative cancellation.
  Preserve completed tasks and snapshot the cancelled attempt's matching task so
  its worker can acknowledge the stop and repeat that acknowledgement safely.
- Apply the same task selection when waiting for cancellation completion.
- Retry rolled-back SQLite application heartbeats after metadata contention,
  preserving the current attempt, deadlines and one progress event per call.

## 2.13.0 - 2026-10-24

- Recognize bounded `ActivityOutcomeRejected` diagnostics in history pages,
  timeline inspection, exports and cold replay. The reader preserves business
  replay positions and does not produce the event or accept a refused outcome.
- Bound diagnostic timeline summaries for storage and tolerate malformed fields
  without changing the original history payload.
- Require reader-equipped applications, Server roles, inspection tools and
  rollback artifacts before enabling the separate rejected-outcome producer.

## 2.12.2 - 2026-10-24

- Read recent failure links and rebuild history-budget counters on PostgreSQL
  when retained messages contain NUL bytes. Preserve canonical history and
  exact byte counts without loading full payloads into diagnostic readers.
- Refuse expired or settled activity callbacks under the original run lock
  before acquiring task or execution locks. Preserve committed renewals and
  the normal live-outcome path. Concurrent retention can finish without a
  reverse lock dependency or restored details.
- Acquire SQLite writer admission before reading a retained run for pruning,
  within the existing transaction policy and original publication budget.
  This correction emits no new history event and needs no new event reader.

- Require the upstream TokenGuard string-validation fix on Laravel 11, raising
  its minimum to 11.56.1. Keep the maintained Laravel 12/13 minimums unchanged.
- Record the newly published Composer TokenGuard advisory against the retained
  Laravel 9–11 upgrade/drain compatibility paths, with an explicit review date.
  Maintained Laravel 12/13 minimums already include the upstream fixes.
- Preserve canonical failure messages beyond MySQL TEXT capacity and across
  NUL-bearing PostgreSQL text parameters. Add nullable encoded overflow storage
  without backfilling existing rows, retain the exact public message and keep
  compatibility previews bounded. Metadata-lock waits are bounded and a package
  rollback retains the added storage and failure evidence.

## 2.12.0 - 2026-10-23

- Preserve UTC instants and microseconds in datetime search attributes and
  embedded visibility predicates, including values with non-UTC offsets.
- Add `workflow:v2:repair-search-datetimes` to preview or explicitly repair
  selected datetime projections from matching retained, typed history.
- Bound derived timeline summaries by UTF-8 bytes so a large accepted activity
  failure can be recorded without overflowing MySQL TEXT storage. Preserve the
  full failure evidence and callback-authority recovery notice.

## 2.11.4 - 2026-10-23

- Preserve whole-valued floats and signed zero in CLI history-export JSON,
  including nested memo projections. Untouched compact and pretty exports
  retain their original canonical checksum and pass integrity verification.

## 2.11.3 - 2026-10-23

- Refuse dependency injection that would silently discard recorded activity
  input before queued or local activity code runs. Record a typed attempt
  failure and preserve the authored retry policy, original input and identity.
- Record activity entry dependency-resolution failures through the normal
  outcome path instead of leaving the attempt leased without an outcome.

## 2.11.2 - 2026-10-23

- Deliver unpinned activity tasks to eligible versioned workers during rolling
  deployments. Preserve build pins, namespace and queue isolation, activity-type
  routing, readiness and availability filters.

## 2.11.1 - 2026-10-22

- Preserve microsecond precision when selecting expired task leases, available
  tasks and elapsed repair backoffs. A repair pass can select a task at its
  recorded deadline without waiting for the next whole second.

## 2.11.0 - 2026-10-22

- Add the opt-in `WorkflowOptions::overlapReleaseAfter` delay for legacy
  workflow, activity, signal and child-completion jobs contending on overlap
  locks. Preserve the zero default, stored options, lock ownership and expiry.
- Preserve the delay through child inheritance and continue-as-new. Queue
  releases still count toward Laravel attempt limits.

## 2.10.2 - 2026-10-22

- Preserve recorded activity lease expiry and worker deregistration in canonical
  attempt views, including exhausted retries and cold history reads. Keep
  ordinary failures and unavailable lease-loss reasons distinct.

## 2.10.1 - 2026-10-21

- Maintain eligible embedded activity leases from a fresh metadata-only PHP
  process while the original callback keeps its process, injected services,
  captured state and application connections.
- Preserve original task, worker, session and business-deadline authority.
  Lease maintenance does not fabricate application heartbeat progress, and
  stops when its original parent exits. Join the helper before recording an
  outcome, and fence publication after authority is lost.
- Reopen the actual current SQLite journal mode instead of reapplying a stale
  startup setting. Environments without the required process capabilities keep
  the original activity grant.

## 2.10.0 - 2026-10-21

- Add the optional `ActivityLeaseRenewal` bridge role and configurable activity
  task leases, with the existing 300-second default. Runtime renewal records no
  application progress and cannot extend authored activity or workflow timeouts.
- Preserve original run/execution deadlines during heartbeats, runtime renewal
  and outcome publication, including external payload preparation.
- Settle expired or explicitly revoked activity attempts through their recorded
  retry limits and backoff. Retain lease-loss diagnostics and terminal failures;
  repair no longer silently redispatches the same running attempt.
- Fence worker-departure recovery by the original namespace, holder and task
  generation. Refuse contested repair locks without changing normal worker lock
  order or claiming that the old callback physically stopped.
- Automatic worker maintenance and published long-activity recovery require
  their separately qualified Server/SDK or embedded integrations. This package's
  renewal role alone does not keep an inline callback's lease alive.

## 2.9.0 - 2026-10-21

- Prepare optional embedded OpenTelemetry spans and log correlation across
  durable admission, tasks, activities, retries and recovery. Applications own
  providers, sampling, exporters and shutdown. Tracing is disabled by default.
- Keep bounded observability context separate from business payloads, command
  equality and lease authority. Native catalogs and service wire adoption are
  separately qualified.
- Require OpenTelemetry API 1.10.1 or later in the 1.x line when that optional
  API is installed, excluding the older incompatible baggage parser.

## 2.8.0 - 2026-10-20

- Add opt-in bounded live execution for persistent embedded database-queue
  workers, retaining at most four eligible frames for a nonrenewing 30 seconds.
- Preserve committed-history, task-generation, connection/session and build
  fences. Unsupported work, worker loss and uncertain acknowledgements use cold
  replay; no database migration or portable protocol change is required.

## 2.7.1 - 2026-10-20

- Chunk long activity retry and workflow-task delays to the queue driver's
  dispatch ceiling, using the existing timer transport mechanism. SQS receives
  a delay of at most 900 seconds per wakeup.
- Defer early workflow-task deliveries without taking a lease, running user
  code, consuming execution attempts or appending history. Preserve original
  task availability, retry history and workflow deadlines. Queue drivers without
  a delay cap retain their exact delays.

## 2.7.0 - 2026-10-20

- Deliver eligible signals accepted during a remote workflow task before its
  successful terminal decision. Retain original deadlines and ordered message
  delivery through cold replay.
- Carry eligible received instance-scoped signals with unapplied accepted updates across
  continue-as-new, preserving identities, payloads and command order. Run-scoped
  signals keep their selected run.
- Close signals a terminal run did not apply with an explicit rejected status,
  reason, timestamp and linked history event. Keep the original durable
  acceptance receipt unchanged.
- Keep missing legacy declarations unknown. A receipt alone cannot establish a
  handler or authorize continuation handoff; unresolved signals close explicitly.
- Upgrade all direct database readers before recording `SignalRejected`.
  Older Core history enums cannot parse the new event; drain old readers
  during the upgrade and do not roll them back onto affected histories.

## 2.6.3 - 2026-10-19

- Exclude retention-pruned runs from missing run-summary drift and lag metrics.
  Retained runs still count in the drift report's total run count.
- Skip marked pruned runs in every projection rebuild mode, including explicit
  run and instance selection. Genuine missing projections remain eligible for
  repair. Unmarked legacy absence is not assumed to be deliberate pruning.

## 2.6.2 - 2026-10-18

- Reuse small unchanged JSON history payloads on the default Eloquent model to
  avoid decoding them again on each read. Cached entries are limited to 4 KiB
  of JSON and 128 recursive values. Larger payloads use the ordinary cast,
  without a new workflow data or admission limit.
- Preserve configured models, custom casts and JSON decoders, payload mutation,
  raw stored bytes and model serialization. This change does not enable retained
  workflow execution or establish a production performance gain.

## 2.6.0 - 2026-10-18

- Deliver cooperative cancellation to a caller waiting for an owned Nexus
  service workflow to complete. The caller waits for the target's cleanup and
  resumes at its original service-call boundary.
- Preserve the original call, cancellation lineage and cleanup deadline through
  duplicate requests and cold replay. Calls returning an existing workflow do
  not acquire cancellation authority over it.
- Service-mode callers require a compatible runtime and SDK with the
  `nexus_cooperative_cancellation` capability. Upgrading this package alone does
  not update a pinned Server image or SDK.

## 2.5.15 - 2026-10-13

- Preserve unclaimed database-queue jobs when SQLite refuses a reservation write
  and the original root transaction has fully rolled back. Laravel's worker
  reports the error and reselects work without failing the workflow before its
  handler runs.
- Install the guard for the default SQLite database connector on affected Laravel
  12/13 versions. Preserve custom connectors, queue managers, other database
  drivers and existing handling of invalid jobs or uncertain transaction outcomes.
- Restart queue workers after upgrading. Already failed workflows remain failed.

## 2.5.12 - 2026-10-11

- Enforce a Nexus service call's original deadline during admission, background
  repair and terminal transitions. Duplicate requests, retries and catalog
  edits preserve the original budget. Late target completion cannot replace
  the caller's typed timeout.
- Validate relative and absolute deadline policies and preserve UTC microsecond
  precision. The migration backfills deadlines from original stored acceptance
  times and rejects unsupported historical policies with the affected call ID.
  Back up the database and replace old application and worker processes before
  applying the migration.

## Unreleased

- Check for the engine's tables on the configured `workflows.storage.connection`
  instead of the application's default connection when gating the V2 task
  watchdog, worker-compatibility heartbeats, and the v1 watchdog. With a
  dedicated storage connection those gates were always false, so expired
  leases were never repaired and activity timeouts never enforced. Behaviour
  on the default connection is unchanged.

## 2.5.7 - 2026-10-10

- Reuse a workflow's first patch or version decision for repeated calls without
  recording duplicate markers. Continue validating every call's supported range.
- Replay duplicate markers from older releases without changing their history
  positions or the recorded clock values observed by retained calls. Executor,
  query reconstruction and fiber replay share this compatibility behavior.

## 2.5.5 - 2026-10-10

- Preserve complete terminal cancellation and termination diagnostics when a
  reason contains a literal NUL. History and failure rows use the same lossless
  JSON string literal on every supported database, while Avro keeps the original
  reason. Ordinary messages and SDK cancellation results retain their behavior.

## 2.4.6 - 2026-10-06

- Keep an explicitly supplied run authoritative during task compatibility
  resolution, including when its marker is null. Avoid the redundant run
  relationship lookup and lazy-loading violations reported in #636. Preserve
  task-marker precedence and the fallback for callers that do not supply a run.
  Thanks to @vanducng for the report, fix and regression tests in #637.

## 2.2.20 - 2026-09-29

- Require patched Laravel 12.69.0 or 13.30.0 minimums for the current framework
  lines. The existing legacy upgrade policy tracks the debug-page XSS advisory
  on Laravel 9 through 11, where an upstream patch is unavailable.
- Preserve UTC archive instants when hydrating workflow runs and their summaries
  in applications with a non-UTC timezone. Archive commands read the UTC clock
  explicitly, and archive timestamps retain microseconds and their original
  metadata across repeated commands.

## 2.0.17 - 2026-09-22

- Resolve a committed caller-supplied workflow identity from a current MySQL
  read when a concurrent start wins after the caller's repeatable-read snapshot.
  Duplicate-start policy, durable-type validation, and transaction rollback
  remain intact.

## 2.0.16 - 2026-09-21

- Recover missing or stale history-budget summaries without sorting large
  payloads or exhausting MySQL's regular-expression work limit. Exact byte
  counts and first-valid parallel-group fan-out are preserved.
- Select worker history-page keys before loading payloads, keeping large inline
  values out of MySQL's sort buffer without changing page size, order, or leases.

## 2.0.15 - 2026-09-21

- Let readiness callers preserve original schema-inspection exceptions through
  `WaterlineEngineSource::status(throwOnInspectionFailure: true)`. Temporary
  database connection failures can then be distinguished from genuinely missing
  tables. Default Waterline diagnostics and engine selection are unchanged.

## 2.0.14 - 2026-09-12

- Isolate deterministic workflow clock values from caller mutation. Deriving a
  deadline with `Workflow::now()->addHour()` no longer advances workflow time or
  expires the deadline immediately. Mutating a timestamp supplied to the fiber
  context cannot change its stored time either. History-based clock advancement,
  timestamp precision, timezones, and mutable or immutable Carbon types remain
  unchanged.

## 2.0.13 - 2026-09-11

- Reduce repeated database lookups during full timeline, wait, and timer
  projection, and reuse activity and timer metadata within a summary pass.
  Existing model hooks, configured tables and connections, duplicate-key
  recovery, and history-based repair remain intact. This reduces repeated
  projection overhead without changing durable history or execution semantics.

## 2.0.12 - 2026-09-10

- Preserve fractional timestamp precision when selecting due schedules. SQLite
  schedules now dispatch during their original due second instead of waiting
  for a later tick; fractional deadlines remain exact on supported databases.
  Future deadlines are not dispatched early, and repeated ticks preserve the
  original occurrence identity without starting it twice.

## 2.0.11 - 2026-09-09

- Preserve activity deadlines after an expired worker lease is repaired.
  Pending and repaired activities cannot start another attempt after an elapsed
  deadline, and the normal timeout sweep can settle the expired current attempt.
  Schedule-to-close timeouts remain terminal across worker restarts without
  accepting a late completion or running an extra activity effect.

## 2.0.10 - 2026-09-09

- Recognize Laravel's native `mariadb` database driver in backend readiness and
  capability reporting. MariaDB no longer appears unsupported when configured
  with its own driver instead of `mysql`. Both driver paths retain real-database
  locking and history checks; the native driver also exercises readiness and replay.

## 2.0.9 - 2026-09-09

- Terminal activity timeouts restore their recorded exception type during
  replay, allowing workflow catch and finally blocks to recover and clean up.
  This also covers local activities and existing persisted timeout histories;
  applications do not need an exception mapping to resume them.

## 2.0.8 - 2026-09-08

- Concurrent embedded child completions persist their outcomes before checking
  the parallel barrier, preventing a lost parent wake-up under MySQL repeatable
  reads. Existing repair paths recover stranded parents from durable child
  terminal history without rerunning completed children.
- A child that handles an activity failure and completes no longer propagates
  historical failure metadata as its result. Genuine terminal failures retain
  their own failure identity across embedded and service-mode completion paths.
- Parent-notification errors roll back the workflow task for repair instead of
  appending a workflow failure after completion. This does not rewrite existing
  histories that already contain contradictory terminal events.

## 2.0.7 - 2026-09-08

- Activity metadata projections no longer download or decode external arguments
  and results merely to inspect lifecycle state. Summary, task, wait, and parallel
  child checks preserve typed history and attempt metadata without payload-sized
  memory allocations. Explicit decoded activity views retain their existing behavior.

## 2.0.6 - 2026-09-08

- Nexus service-call history projects started, completed, failed, and cancelled
  events without failing workflow-task completion. Timeline entries retain the
  service-call identity and summarize the operation without exposing its payload.

## 2.0.5 - 2026-09-06

- Early queue delivery no longer exhausts activity or timer transport attempts.
  Fractional-second wakeups round up without changing durable deadlines;
  early wakeups use fresh jobs while business retry limits remain unchanged.
  Long SQS timers relay through capped 900-second dispatches.

## 2.0.4 - 2026-09-06

- Durable `finally` cleanup survives ordinary workflow-task suspension and
  discarded replay Fibers. Cleanup activities, timers, and memo writes retain
  normal success/failure replay behavior without running during local teardown.

## 2.0.3 - 2026-09-02

- Schedule ticks share bounded due-work and buffered-drain batches across
  namespaces while preserving oldest-first order within each namespace.

## 2.0.2 - 2026-09-01

- Workflow `2.0.2` reports its stable package identity through the platform
  conformance API, allowing published-package verification after the 2.0 launch.

## 2.0.1 - 2026-09-01

- Workflow `2.0.1` keeps PHP exception traces Avro-portable when argument
  capture is enabled, preserving each frame's durable fields while omitting
  object and resource values that cannot cross the public payload boundary.

## 2.0.0 - 2026-09-01

- Workflow `2.0.0` promotes the fully qualified release-candidate runtime to
  the stable 2.0 line without changing its durable execution behavior.

### 2.0 prerelease history

- Workflow `2.0.0-rc.55` treats the official stable-v1 Y and Base64 serializer
  settings as nonblocking migration diagnostics while v1 runs drain, without
  changing the Avro-only codec used for all new v2 payloads.
- Workflow `2.0.0-rc.54` validates service-mode parallel groups in the durable
  yielded-command sequence domain, so signal-resumed condition waits retain
  flat and nested mixed-group identity across cold replay and worker replacement.
- Workflow `2.0.0-rc.53` preserves durable rejection reasons in history-export
  command rows whose request payload is stored externally, matching run-detail
  audit output while keeping accepted-command payload reasons private.
- Workflow `2.0.0-rc.52` preserves the durable rejection reason on rejected
  signal command audit rows after a fresh read, including when the request
  payload is stored externally. Platform conformance suite 47 now pins the
  invalid-argument and unknown-signal audit shapes and their no-mutation
  invariants.
- Workflow `2.0.0-rc.51` keeps retained protocol 1.18 local-activity attempt,
  heartbeat, and retry-policy objects open to additive worker fields while
  protocol 1.19 preserves the final closed report grammar. The package-owned
  rolling-upgrade metadata states the 1.19 attempts requirement and nested
  shape boundary explicitly so dependent current protocol mirrors can select
  the same negotiated behavior.
- Workflow `2.0.0-rc.50` reconstructs portable local-activity attempts as a
  non-overlapping timeline using reported durations and retry backoff. Each
  heartbeat timestamp is derived from its attempt start, aggregate heartbeat
  state retains the chronologically latest report, and history snapshots now
  expose lifecycle-correct running, retry, and terminal states through cold
  persistence, export/import, and replay.
- Workflow `2.0.0-rc.49` finalizes the portable local-activity command as an
  ordered `record_local_activity.attempts` report. Worker attempt identity is
  preserved independently of durable server attempt identity across every
  attempt-scoped history event, cold persistence, export/import, and replay.
  Reported heartbeat elapsed time now determines persisted heartbeat time
  instead of workflow-task completion time. Negotiated protocol 1.18
  completions that predate attempt reports remain accepted as one terminal
  attempt, while protocol 1.19 selects the strict ordered report grammar.
- Workflow `2.0.0-rc.48` makes SDK-reported local-activity attempt sequences
  authoritative. The command grammar rejects unordered, contradictory, or
  over-limit attempts and heartbeats, while service-mode completion projects
  each attempt and terminal failure into the same operator-facing records used
  by embedded execution. Worker-reported attempt identities remain scoped to
  their activity executions while server-owned ULIDs keep durable attempt and
  history references globally safe. Platform conformance suite 47 publishes
  this typed grammar without changing the retained protocol 1.18 bytes.
- Workflow `2.0.0-rc.48` owns method-dependency resolution across supported
  Laravel versions, including contextual attributes, enum defaults, supplied
  class parameters, and `self` and `parent` parameter types. The v2 unit suite
  now publishes a machine-readable, branch-bound coverage summary and enforces
  a non-decreasing baseline against the complete production source inventory.
- Workflow `2.0.0-rc.48` adds deterministic durable selection and structured
  concurrency for activities, child workflows, timers, signal waits, and
  condition waits. Persisted winner markers remain authoritative across cold
  replay, non-winning handles can continue, be awaited, or be cancelled, and
  service workers share the same protocol-gated selection identity and member
  key contract as the embedded Laravel runtime.
- Workflow `2.0.0-rc.47` defines the host-facing workflow control plane around
  ordinary workflow operations. Protocol 1.18 adds authoritative local-activity
  command normalization and atomic attempt, heartbeat, retry, failure, timeout,
  cancellation, and result history so replay never repeats a recorded local
  side effect. Platform conformance suite 46 publishes the retained protocol
  1.18 OpenAPI and AsyncAPI authorities for local activities, worker sessions,
  and sticky execution while preserving the immutable 1.17 artifacts.
  Reserved message-stream delivery uses a
  separate runtime-only interface implemented by the default control plane,
  while public signals remain unable to inject the reserved transport name.
  The release audit also dereferences every advertised current and retained
  worker-protocol resolver and verifies its SHA-256 digest before accepting
  the public conformance mirror.
- Workflow `2.0.0-rc.45` preserves an SDK-authored condition-wait occurrence
  identity across open, satisfied, timed-out, and timeout-timer history.
  Signal- and update-driven physical re-evaluations retain that identity, so
  adjacent authored waits remain independent without inferring identity from
  condition keys or predicate fingerprints. Worker protocol 1.17 gates the
  occurrence field and registration capability so older Server nodes reject
  the command before execution instead of silently dropping its replay
  identity. Platform conformance suite 45 publishes the versioned 1.17
  OpenAPI and AsyncAPI authorities and retains the prior 1.16 bytes.
- Workflow `2.0.0-rc.44` separates the packaged history-export schema carrier
  path from its retained immutable origin. Runtime manifest validation and the
  published-package audit now enforce the same release, origin path, resolver,
  artifact, and digest tuple while continuing to verify resolver bytes against
  the current package carrier.
- Workflow `2.0.0-rc.43` preserves canonical search-attribute type identity
  through worker completion, full and paginated history, database restart, and
  replay. Worker protocol 1.16 gates declared type metadata so older Server
  nodes reject it instead of silently inferring a different type, while legacy
  events without metadata retain an explicit unknown-type compatibility rule.
  Platform conformance suite 44 publishes the versioned 1.16 OpenAPI and
  AsyncAPI authorities and retains the prior 1.15 bytes.
- Workflow `2.0.0-rc.42` makes non-indexed memo updates portable across
  service-mode workers. PHP workflow authoring emits the language-neutral
  Avro payload-envelope command and consumes only entry-matching memo history
  during execution, cold replay, and query replay. Memo history and the
  authoritative memo projection retain lossless Avro envelopes across database
  reloads, preserving numeric branches, binary values, and map identity. Memo
  patches are normalized independently of the current run state, while
  structural key and encoded-size limits are enforced on the merged memo so
  at-limit replacements remain valid. Integer-like top-level keys are rejected
  before a host runtime can coerce their portable map identity.
  History exports keep that envelope as the import authority while exposing a
  separate JSON-safe memo projection, so export/import preserves exact memo
  identity for operator readback and replay after a fresh database boundary.
  Platform conformance suite 43 retains the version-one export schema bytes
  while binding version two to the JSON-safe projection and lossless Avro memo
  authority at the candidate's retained Workflow release source.
- Workflow `2.0.0-rc.41` removes the unsupported root Composer Dependabot
  update path for this lockfile-free library. GitHub dependency alerts remain
  enabled, automated security-fix pull requests remain disabled, and the
  scheduled per-major Composer audit continues to reconcile Laravel 9-13 with
  the accepted-risk policy.
- Workflow `2.0.0-rc.40` raises the embedded runtime's Laravel minimums to 9.52.17, 10.48.29,
  11.44.1, 12.61.1, and 13.12.0 so every supported major receives all
  available upstream fixes. Laravel 9-11 remain available for embedded upgrade
  and drain paths, but upstream has not patched their remaining active
  advisories; applications on those lines should move to Laravel 12.61.1 or
  Laravel 13.12.0 and newer. A scheduled per-major Composer audit now owns this
  library dependency instead of Dependabot trying to infer one installed
  version from the package's intentionally unlocked multi-major constraint.
- Workflow `2.0.0-rc.39` keeps database-backed Watchdog chains on fresh
  delayed jobs, preserving queue affinity without accumulating attempts. A
  generation lease converges duplicate ticks and safely releases ownership
  when successor dispatch fails.
- Workflow `2.0.0-rc.38` publishes worker protocol 1.15 as the current
  conformance authority. The package contract and retained OpenAPI and AsyncAPI
  specs now define the version-gated message-stream completion fields together.
- Reserved runtime delivery now keeps durable message-stream input separate
  from user-authored one-shot signals. The Workflow package source advances to
  `2.0.0-rc.37` with worker protocol 1.15 for portable stream consumption.
- Service-mode workflow tasks can now merge non-indexed memo metadata through
  the language-neutral `upsert_memo` command. The runtime records canonical
  replay identity and merged projection data, preserves memo across
  continue-as-new, enforces structural limits, and fences duplicate task
  completions.
- Standalone child-workflow completion now applies the recorded parallel-group
  path before resuming the parent. Successful child-only and mixed groups wait
  for every member, serialize concurrent child closures on the parent run, and
  create a single replay task while preserving retry and fail-fast behavior.
- Advanced the Workflow package source to `2.0.0-rc.36` for the service-mode
  child-completion barrier.
- Standalone workflow-task completion now validates and records deterministic
  parallel activity, child-workflow, and timer metadata. Nested groups must be
  complete and sequence-aligned before transport, and timer waits now expose the
  same group/path diagnostics as activity and child waits.
- Advanced the Workflow package source to `2.0.0-rc.34` for the service-mode
  parallel-group contract.
- Advanced the Workflow package source to `2.0.0-rc.33`. Laravel now
  container-constructs embedded v2 workflow and activity classes before the
  engine binds durable runtime context. A package-owned transition contract,
  isolated v2 queue default, upgrade-status command, supported-intersection
  matrix, and published-artifact smoke make the stable-v1 to embedded-v2 path
  executable without transferring v1 history.
- Advanced the Workflow package source to `2.0.0-rc.32`. History import now
  validates Avro codec declarations only at schema-owned payload rows and
  envelopes, preserving codec-looking memo and search-attribute data while
  real non-Avro payload declarations continue to fail closed.
- Platform conformance suite version 41 makes the lifecycle-neutral worker
  protocol OpenAPI bytes the current conformance authority. The former
  beta-worded binding remains available only through its explicit historical
  identity, while protocol version 1.13 and every wire shape remain unchanged.
- Advanced the Workflow package source to `2.0.0-rc.14` for the corrected
  conformance authority. Durable Workflow 2.0 remains a release candidate.
- Platform conformance suite version 40 publishes the revision-bound CLI
  output-schema manifest and its complete JSON envelope plus JSONL record
  schema closure with HTTPS identities and SHA-256 byte bindings. The
  suite-39 revision remains retained with its original bytes.
- Advanced the Workflow package source to `2.0.0-rc.13` for platform protocol
  catalog 16. Its conformance bindings resolve the catalog and protocol-spec
  bytes through immutable public documentation provenance. The aggregate
  recommended product tuple remains RC5 until independently published
  release-candidate artifacts pass exact-current qualification.
- Historical command-contract gaps remain visible in operator metrics without
  warning fleet correctness once their runs are closed. Open runs still warn
  when operator command forms lack required safety data.
- Projection repair can be limited to one namespace, and resolved wait
  snapshots now compare absent optional values consistently with projected
  nulls so repeated repair is idempotent.
- Replaced the prerelease JSON-in-Avro wrapper with the fixed recursive
  `durable_workflow.protocol.Value` schema and standard Avro single-object
  framing. Native adapters now preserve booleans, signed 64-bit integers,
  finite doubles, bytes, UTF-8 text, lists, and string-keyed maps.
- Added a backup-first prerelease-history migration that recursively rewrites
  inline and external wrapper copies in retained event snapshots. Replacement
  external objects receive verified hashes and sizes while original objects
  remain recoverable, and affected exported histories must replay before the
  migration reports success.
- Removed the package-owned hosted control-plane and runtime-target contract.
  Embedded Laravel, independent self-hosted Server, and managed Cloud remain
  separate deployment choices; Cloud placement stays behind the namespace
  endpoint.
- Platform conformance suite version 37 Rust signal/query scenarios install
  the exact synchronized `durable-workflow =2.0.0-rc.5` crates.io artifact,
  with prior observed bindings preserved by source revision and digest.
- Embedded and standalone activity heartbeats now use the same
  attempt-before-execution row-lock order as timeout enforcement. Accepted
  heartbeats renew the current attempt deadline, while stale scanner snapshots
  cannot time out live, replaced, or already-closed attempts.
- Release recovery now accepts release-candidate plans only when they retain a
  coherent immutable beta qualification. The versioned recovery-consumer
  conformance contract and release-docs audit now cover the `rc` channel.

- Release-plan recovery now consumes immutable, exact-version release-note
  preparation authority before publishing a newly recorded plan.
- Explicit release recovery rejects terminally superseded plans before and
  after publication preflight while keeping completed-plan verification
  idempotent.
- Standalone workers now receive accepted declared signals even when the host
  has no embedded workflow definition or local wait projection. Signal tasks
  retain command order ahead of queued updates, and QueueFake update completion
  uses the configured workflow-run model query. Accepted signal inputs are also
  persisted on their history event so public-history consumers observe the same
  values as workers and query replay.
- Workflow-task claims and renewals now resolve
  `workflows.v2.workflow_task_lease_seconds` at runtime across remote,
  queued, timer, local-activity, and repair-driven execution paths. Embedded
  Laravel hosts retain an explicit 300-second default and may set
  `DW_V2_WORKFLOW_TASK_LEASE_SECONDS` before caching configuration.

## 2.0.0-alpha.179

Workflow 2.0.0-alpha.179 keeps the Durable Workflow 2.0 PHP package
conformance claim aligned to platform conformance suite version 12. For this
alpha, upgrade-path migration runtime coverage remains outside the release
claim; claiming that category requires a versioned migration scenario manifest
and published-artifact conformance evidence.

- `php artisan workflow:v2:replay-conformance` now reports `outcome: pass`
  when every Workflow PHP replay shard scenario passes, so host replay
  conformance can compose the PHP shard with Python and server evidence
  without treating the shard itself as non-passing.
