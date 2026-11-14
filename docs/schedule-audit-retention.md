# Schedule audit retention

Embedded schedules keep at most 1,000 audit snapshots by default. The first
retained event (normally `ScheduleCreated`) and the newest events remain in
their original form, with their original sequence numbers. New events continue
after the latest retained sequence. Set `workflows.v2.schedules.audit_history_limit`
or `DW_V2_SCHEDULE_AUDIT_HISTORY_LIMIT` to an integer of at least 2 to change the
cap. This behavior requires the schedule occurrence migration and updated
scheduler code; it is source work pending publication.

Every audit append compacts at most 100 old snapshots inside the schedule's
write transaction. A newly created schedule stays within its configured cap.
A legacy schedule already above the cap approaches it in bounded passes.
To compact an idle legacy schedule, repeat:

```sh
php artisan workflow:v2:schedule-audit-prune --namespace=orders --schedule=daily-invoices
```

The JSON response reports deleted and physical retained counts, including rows
hidden by application query filters. Stop when the retained count reaches the
configured limit. An invalid limit refuses compaction.

## Firing receipts and upgrades

An audit snapshot is not the authority for whether an occurrence may start
again. Compact occurrence receipts preserve that decision after snapshots are
removed. They contain only the internal schedule ID, namespace and canonical
UTC occurrence time. They remain until the schedule is physically purged;
the snapshot cap does not bound this correctness data. Old trigger metadata
is copied before its audit row is deleted. Malformed occurrence metadata refuses
the transaction and retains the original audit evidence.

Stop old scheduler and schedule lifecycle writer processes before enabling
compaction. Older packages read
audit history for deduplication and cannot read the new compact receipts. For
a staged upgrade, explicitly set `audit_history_limit` to PHP `null` in all
new processes until every old scheduler and lifecycle writer has stopped. This temporarily
disables snapshot compaction. Then enable the configured cap. Setting this
value to zero or an invalid string does not disable retention; it is an error.
The migration creates the receipt table without scanning all existing history.
A schema rollback refuses to discard populated receipt storage.

## Purged schedules

Deleting a schedule through the API marks it deleted and keeps its bounded
audit history and firing receipts. Once the schedule row is physically removed,
run the following command in that namespace:

```sh
php artisan workflow:v2:schedule-audit-prune --namespace=orders --limit=500
```

Each pass deletes at most the selected number of orphan audit rows and receipts
combined. Repeat until both deleted counts are zero. A remaining schedule row,
including a soft deleted schedule, protects its rows. Namespace comparisons are
exact; the command never sweeps other namespaces. Omission selects the configured
namespace, or `default` when none is configured. Use `--null-namespace` instead
of `--namespace` only for legacy rows whose namespace is SQL `NULL`.

Custom schedule, audit and occurrence models use the configured workflow storage
connection and table prefix. Applications which replace tables must also provide
the corresponding migration and configure `workflows.v2.schedule_occurrence_model`.
Application query filters cannot hide firing receipts or make a physically
existing schedule eligible for orphan cleanup. The maintenance paths enforce
namespace and internal owner identity explicitly.
