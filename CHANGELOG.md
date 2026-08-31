# Changelog

## Unreleased

- Workflow `2.0.0` promotes the fully qualified release-candidate runtime to
  the stable 2.0 line without changing its durable execution behavior.
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
