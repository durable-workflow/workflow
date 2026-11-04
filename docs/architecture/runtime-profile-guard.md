# Guard-only runtime profile floor

Phase G is the [approved prerequisite](https://github.com/durable-workflow/workflow/pull/1030#issuecomment-6637201487)
for the prospective receive work in #1024. It does not repair historical query
results. No active profile, task kind, queued job, history observation field or
receive writer is part of this phase.

## Admission and storage

`workflow_runs.runtime_profile` is an engine-owned nullable scalar bounded to
64 ASCII bytes. Null is the only legacy value. Phase G supports no non-null
profile, including a syntactically valid future token. The token grammar is
`\A[a-z][a-z0-9._-]{0,63}\z`, compared byte-for-byte without trimming,
normalization, coercion or database-collation equivalence. Configuration and
worker compatibility wildcards cannot extend the supported set.

New legacy runs receive null internally. Caller metadata, generic mass
assignment and request/import data cannot set or clear this field. Existing
runs are not converted. The profile is immutable after admission, including
through dirty-model saves and bulk updates.

The expand-only migration and guard must cover the actual configured run
model/table/storage connection as well as the default table. Before dropping
the discriminator, down migration must read the complete in-scope inventory
and refuse if any non-null value exists or that inventory cannot be verified.
A missing column, omitted/stale model value, wrong storage lookup or incomplete
schema is a refusal/health failure, never evidence of legacy null.

Existing column type/nullability/default, index columns and trigger/function
semantics must match the guard contract before adoption. A deterministic object
name alone is insufficient. Connection aliases for the same physical table are
one migration target.

Automatic down is limited to one physical SQLite or PostgreSQL table inside a
single DDL transaction after complete validation. Nontransactional drivers or
multiple physical targets refuse before any DDL and retain every object.
Rollback does not rely on reinstalling a trigger after a partial failure.

Apply the expand migration before deploying code that requires this column.
A failed or missing migration remains a storage health failure. Removing the
column while guard readers are active is not a supported rollout sequence.

## Refusal before effects

Read the discriminator authoritatively with the configured connection,
namespace, instance and run identity. Do not trust a task, projection or cache
as its authority. The guard must work when all task rows have been deleted.

Guard creation, dispatch, poll/claim, service delivery, repair/watchdog,
projection, query/replay, continuation, redrive and import before their first
durable or application effect. Include direct/manual entry points and all
catch/failure handlers. Refusal must not create a failed task/run, increment a
repair/claim attempt, change a projection or observe a continuation cache.
SQL candidate filters are useful but do not replace the authoritative guard.

Controls compare complete same-schema snapshots of tasks including
lease/sticky/claim/dispatch/repair fields, run state, service delivery,
summaries/projections, failures, history, retained messages, cursor and
continuation/cache state. Include application construction and entry/update/
query invocation counts. Cover SQLite, MySQL and PostgreSQL, configured models
and storage, missing tasks, stale models, default/custom projection roles,
manual operations, failure injection and guarded migration rollback. A later
enum exception after a committed claim is not a passing refusal.

## Input and rollout boundaries

Existing version-2 history input acceptance is preserved. General declined a
global 16 MiB legacy cutoff in this phase. Candidate version-3 ingress remains
disabled. A later versioned input route/discriminator needs an explicit hard
bound before decode and controls for reordered or false version declarations.
No hand-written JSON parser or parallel meter is introduced here.

Guard source tests and an eventual published guard artifact do not prove that
every executor and maintenance process has adopted the floor. A future
new-run-only producer requires separate proof of an enforced minimum across
execution, repair, watchdog, query/import, cache and rollback paths. Once
protected runs exist, unguarded 2.20.3 is not a safe rollback. Storage/routing
isolation is the alternative when that minimum cannot be enforced.

The rejected cursor prototype, empty-receive guard, deferred schemas and
activation proposals remain in [the draft's history and review packets](https://github.com/durable-workflow/workflow/pull/1030).
They do not authorize observation writes, a production migration, deployment,
runtime experiment, release or downstream adoption.
