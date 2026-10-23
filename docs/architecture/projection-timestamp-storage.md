# Timeline and wait projection timestamps

The `workflow_run_timeline_entries.recorded_at` column and
`workflow_run_waits.opened_at`, `deadline_at`, and `resolved_at` columns store
UTC wall times with microsecond precision. They are timezone-free database
columns; model hydration interprets them as UTC. The incremental child
resolution update also writes UTC explicitly because query updates bypass
Eloquent casts. The history event and other durable runtime rows remain the
source of truth for projections.

Previously, the default datetime cast could write a UTC value and read it back
as local time. In non-UTC applications this shifted operator-facing timestamps
and made `--needs-rebuild` repeatedly select the same run. Old projection rows
may contain either UTC or local wall times, depending on the write path. Do not
bulk-convert them by assuming one timezone.

After upgrading all projection writers, back up the workflow database and run
`workflow:v2:rebuild-projections --needs-rebuild --dry-run --json` to inspect
the affected run count. Then run the same command without `--dry-run`, or use
`--run-id` to repair selected runs in batches. Recheck drift and run the dry
run again; an unchanged source should select no runs. Verify timeline and wait
views show the intended instants and that operator projection warnings clear.
The rebuild changes derived rows only. It cannot recover an offset already
lost from authoritative history; investigate such rows under the history
timestamp procedure before interpreting a rebuilt projection as corrected.

## Datetime search attributes

Datetime search attributes also store UTC instants with microsecond precision.
An input such as `2026-10-08T12:34:56.123456+02:00` represents
`2026-10-08T10:34:56.123456Z`. Offset conversion must happen before writing the
timezone-free column and before binding a visibility predicate. Hydration uses
UTC even when the application's default timezone differs.

Earlier writers could store the input's local wall time while retaining its
offset in the `SearchAttributesUpserted` history event. Upgrading the writer
prevents new errors but does not identify or rewrite those existing rows.
Never infer an original offset from a stored wall time.

The unreleased `workflow:v2:repair-search-datetimes` command derives a repair
from the latest retained, explicitly typed upsert for each selected key. Back
up the database and upgrade every writer before inspecting affected runs:

```sh
php artisan workflow:v2:repair-search-datetimes RUN_ID --namespace=production --json
php artisan workflow:v2:repair-search-datetimes RUN_ID --namespace=production --key=SubmittedAt --json
```

The default is a preview. `would_repair` reports the number of selected rows
whose stored value differs from their history-backed UTC value. Review the
selected run and keys, then explicitly apply the same selection:

```sh
php artisan workflow:v2:repair-search-datetimes RUN_ID --namespace=production --key=SubmittedAt --apply --json
```

The command locks the selected run and attributes, validates all selected keys
before writing, and updates the run summary in the same storage transaction.
It preserves history, business commands, run/task identities and leases. A
repeat apply reports zero repairs when the selected values already match.
The command operates on one exact run. `--namespace` adds an equality guard;
omit it only when the exact run ID is sufficient, including legacy null
namespaces. Repeated `--key` options restrict the operation to those keys.

Pruned history, missing or stale typed evidence, and timestamps without an
unambiguous timezone are refused. A selected key must still be a datetime
attribute with the matching original upsert sequence. One unprovable key
refuses the entire selection. Inspect the original history and select only
proven keys rather than guessing a timezone. An unexpected storage error is
reported as `unverified`; inspect the rows before retrying because the command
cannot promise the outcome of an uncertain database commit.

This command repairs derived search attributes and their summary. It does not
restore pruned history, change authoritative input, or correct the separate
Server visibility-query binding path. That path requires its corresponding
Server fix.
