# Retention marker timezone qualification, 2026-09-29

This synthetic, isolated probe supports [Workflow #585](https://github.com/durable-workflow/workflow/issues/585) and draft PR #587. `waterline_retention_probe.php` creates one completed winter run and one failed summer run, archives them, prunes their details, hydrates the run model, and serializes it through the published Waterline V2 resource. Both application and PHP default timezones are `Europe/Kyiv`. The JSON files are the unedited program output. `notice_timestamp` applies the published Waterline 2.0.7 `timestamp()` display formatting (`T` to space and trailing `Z` removal) to the serialized field.

| Component | Exact artifact |
| --- | --- |
| Workflow baseline | Composer `durable-workflow/workflow` 2.2.18, dist reference `09158ab3fb4cfa5e46e320e2d70853122af209cc` |
| Waterline | Composer `durable-workflow/waterline` 2.0.7, dist reference `99621a34647a2339301515a48f1b4568c7995fce` |
| Application/runtime | Laravel 13.34.0, PHP 8.4.26, tzdb 2026.3 |
| Database | PostgreSQL 17 image `postgres@sha256:d74eeac9a635390a49bc21bd49fccd973de707e2a53a76ac49b552b8712ec46f` |

The baseline ran the probe with the published packages after `php artisan migrate --force`. The candidate run used the same published package tuple and database, with only PR #587's `src/V2/Models/WorkflowRun.php` bind mounted over the Workflow vendor file. The application invoked `php waterline_retention_probe.php` once for each variant. The two variants create new synthetic run IDs and write no customer data.

| Result | Published baseline | Candidate model overlay |
| --- | --- | --- |
| Winter pruning marker at 12:00 UTC | Waterline 10:00 UTC, wrong by 2 hours | Waterline 12:00 UTC, correct |
| Summer pruning marker at 12:00 UTC | Waterline 09:00 UTC, wrong by 3 hours | Waterline 12:00 UTC, correct |
| Terminal status and retained detail counts | Completed/failed status preserved; retained counts zero | Same |
| Raw archive value after pruning | 11:59 UTC in both seasons | Same |
| Waterline archive timestamp | 09:59 UTC winter, 08:59 UTC summer | Same; tracked separately in [#588](https://github.com/durable-workflow/workflow/issues/588) |

The repository regression in `V2WorkflowRunRetentionCleanupTest` additionally covers UTC, both Kyiv seasonal offsets, the instants immediately before and after both 2026 daylight-saving transitions, repeated pruning, raw archive metadata preservation, and terminal status. The full file passed on PostgreSQL 17: 6 tests, 140 assertions. This probe exercises Waterline's resource serialization and its display formatter logic; it does not launch a browser.
