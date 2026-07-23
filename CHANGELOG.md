# Changelog

## Unreleased

- Joined Workflow to the synchronized Durable Workflow `2.0.0-beta.6`
  product train. This is the only supported 2.0 prerelease baseline; earlier
  alphas and beta tuples remain historical and receive no compatibility shim.
- Platform conformance Rust signal/query scenarios now install the exact
  `durable-workflow =2.0.0-beta.6` crates.io artifact from that train.

- Release-plan recovery now consumes immutable, exact-version release-note
  preparation authority before publishing a newly recorded plan.
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
