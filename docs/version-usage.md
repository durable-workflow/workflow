# Open-run version usage (draft v1)

This read-only contract implements durable-workflow/.github#220. It answers which
open runs still have a recorded decision below a proposed minimum supported
version. It does not replay workflows, run application code, write a projection,
change markers, or authorize deleting a branch.

The native control-plane route is `GET /workflow-version-usage`, relative to the
existing control-plane API root. Authentication and namespace selection use the
existing runtime request context. The embedded entry point is
`Workflow\V2\Support\VersionUsageQuery::page()`; its namespace argument is required,
and `null` selects only the embedded default namespace, never all namespaces.
Namespace, workflow type and change ID comparisons are exact and case-sensitive.

## Request

- `workflow_type`: required nonblank UTF-8 string, at most 255 bytes.
- `change_id`: required nonblank UTF-8 string, at most 1024 bytes.
- `min_version`: required signed 32-bit integer, from -2147483648 to 2147483647.
  This is the proposed new `minSupported`, not the largest version deployed.
  For removing a `patched()` legacy branch, use 1.
- `state`: optional `legacy`, `current`, or `unknown`; omitted means all states.
- `limit`: integer from 1 to 200, default 50.
- `cursor`: optional opaque continuation from this exact namespace/type/change/
  minimum/state query. It is not an authorization token. Invalid or mismatched
  cursors are errors; they never fall back to a first page.

Invalid arguments return the existing invalid-request error envelope (HTTP 422
for the native endpoint). Storage or connection failures are errors, not empty
successful answers. Implementations must use the configured storage models and
connection, including custom table names and prefixes.

## Classification

Only runs whose canonical status is open participate (embedded: pending,
running, waiting). Retained closed, archived terminal and prior continued runs
are excluded. An archived run which is still open remains included.

- `legacy`: a retained, valid marker for this exact change ID has version less
  than `min_version`.
- `current`: its recorded version is greater than or equal to `min_version`.
- `unknown`: no marker exists, or retained history cannot establish the decision.

Each run has `recorded_version` only for legacy/current. Unknown reasons are:

- `marker_absent`: retained history has no decision for this change ID, including
  an older definition that has not reached the change point. A matching current
  code fingerprint, build label, admission time or deployment time never upgrades
  this to current.
- `history_unavailable`: details are pruned or owning-instance/source provenance is unavailable,
  the initial history/prefix is absent
  or incomplete, the stored event format is not supported, or the bounded
  inspection budget (1,000 version markers per run) is exceeded.
- `history_invalid`: malformed decision data, invalid recorded range, or
  conflicting decisions for the same change ID. Repeated decisions with the same version remain valid when each recorded
  range is valid; range metadata may differ across repeated historical calls. Marker integer fields use the signed 32-bit range.

Marker readers must understand their runtime's retained format. Embedded marker
fields are `change_id`, `version`, `min_supported`, `max_supported` and positive
logical `sequence`; the range must contain the recorded version. No application
argument, output, memo or payload-store fetch is necessary for this query.

## Response and paging

The response shape is in
[`resources/contracts/version-usage-v1.schema.json`](../resources/contracts/version-usage-v1.schema.json).
`counts.total` equals legacy + current + unknown for the entire bounded open-run
cohort, before the optional state filter and independently of page size/cursor.
`runs` contains only the selected state when a state filter is present.

Runs are ordered by byte-exact run ID ascending, with a strict keyset continuation. The
first request captures the greatest matching open run ID as `through_run_id`;
all continuations retain that upper bound and advance after the last returned
ID. New admissions beyond that bound cannot appear on later pages. Cursors bind
all identity/classification filters, and every page re-applies authorization and
exact namespace predicates. Namespace changes cannot reuse a cursor.

This is an explicitly **live**, read-only observation, not a durable fleet
snapshot or deployment fence. Runs may close or record a marker between pages;
counts are refreshed over the same upper-bounded cohort and may change. Returned
IDs do not repeat within a cursor traversal, but a newly matching state behind
the cursor requires a new traversal. Counts and a page are derived from the same
per-run observations in each response. No response contains a `safe_to_remove`
flag. Operators must keep unknowns visible and restart the query after draining
or otherwise fencing admissions before making a rollout/removal decision.

Example shape (the cursor is opaque):

```json
{
  "schema_version": 1,
  "namespace": "orders",
  "workflow_type": "shipment",
  "change_id": "carrier-api",
  "min_version": 2,
  "state": null,
  "consistency": "live",
  "through_run_id": "01J00000000000000000000002",
  "counts": {"total": 3, "legacy": 1, "current": 1, "unknown": 1},
  "runs": [{"instance_id": "shipment-1", "run_id": "01J00000000000000000000001", "status": "waiting", "state": "legacy", "recorded_version": 1, "unknown_reason": null}],
  "limit": 1,
  "returned_count": 1,
  "has_more": true,
  "next_cursor": "opaque-runtime-cursor"
}
```

This draft is source-contract work only. Native endpoint/CLI/Waterline adoption
and installed compatibility remain owned by their respective implementations.
