# Schedule boundary qualification, 2026-09-29

Issue: [#561](https://github.com/durable-workflow/workflow/issues/561). The `raw/` directory contains the unedited JSON emitted by the `runner/` programs from an isolated Laravel application. All schedule IDs, workflow IDs, and payloads are synthetic. Each case phase was a fresh PHP process. The worker ran separately after scheduling, and the summary files were captured after the queue drained.

`boundary-scenarios.json` lists the repeatable calendar and concurrency criteria for this qualification. It is a supplementary qualification artifact; the existing platform conformance suite's stable schedule manifest remains pinned to its immutable suite version 38 snapshot.

## Artifacts and environment

| Component | Pinned artifact |
| --- | --- |
| Published package under test | `durable-workflow/workflow` 2.2.18, Composer dist reference `09158ab3fb4cfa5e46e320e2d70853122af209cc` |
| Application | Laravel 13.34.0, `dragonmantank/cron-expression` 3.6.0, PHP 8.4.26, tzdb 2026.3, database queue |
| PHP base image | `php:8.4-cli-bookworm`, `sha256:f1d32fb402fffba0b3dd8ba8c0aca474c9e9f04395fa846eedea77c503257dee` |
| MySQL | 8.4.11, `mysql@sha256:0744ee5ef89ce6ccfa13de3e579fe6b9e27f93dd70da9c06d2c908b1b193fb8d` |
| PostgreSQL | 16.15, `postgres@sha256:1a6ab3f5345eb6dbe04a1349529caabdb0ab09293a09590fad07b2246bfa4b54` |
| MariaDB | 11.4.13, `mariadb@sha256:70cc072b29b4a89ae07abb2d4da2c64678a7f2dfe092751bb51c87d67dc1338b` |
| SQLite | PHP 8.4.26 bundled SQLite, file database |

The app was created with `composer create-project laravel/laravel`, then `composer require durable-workflow/workflow:2.2.18`; `php artisan migrate --force` created the app, queue, and Workflow tables. The PHP image added `pdo_mysql` and `pdo_pgsql` to the pinned CLI base. For the candidate checks only, the PR's `src/` and new migration were mounted over the published package and `php artisan migrate --force` was rerun. The published package results never used the candidate files.

For each backend, the invocation sequence was `php probe.php <case> init`, `php probe.php <case> tick1`, and, for fold cases, `php probe.php <case> tick2`. Each due tick was a new scheduler process after setup, including the fire boundary after the schedule's initial process exited. The manual case used `manual` then `stale`. The collision case used `tick1` then `backfill`. Each command wrote its own named JSON file in `raw/`. A fresh process ran `php worker.php` until the database queue emptied, followed by `php summary.php`. MySQL, PostgreSQL, and MariaDB also ran two `php concurrent.php a|b <barrier-name>` processes released by the same barrier during the due minute. `php manual_race.php manual|tick <barrier-path>` likewise ran a manual trigger and a due tick in competing processes. See the runner source for the exact cron expressions, frozen instants, overlap policy, and captured database fields. These tests used isolated database containers without external network ports.

## Published 2.2.18 results

| Case | Intended and observed UTC starts, on all four databases |
| --- | --- |
| New York spring gap, 02:30 local | 2026-03-08 07:30 |
| Kyiv spring gap, 03:30 local | 2026-03-29 01:30 |
| New York fall fold, 01:30 local | 2026-11-01 05:30 and 06:30 |
| Kyiv fall fold, 03:11 local | 2026-10-25 00:11 and 01:11 |

All six distinct calendar instances per backend completed. The four `*-summary.json` files have zero pending and failed queue jobs after worker drain. The manual trigger at the due minute produced one start, followed by a `stale_occurrence` skip on MySQL, PostgreSQL, and MariaDB. Two competing scheduler processes at the same due minute produced one start and one stale skip on those three databases.

The competing manual/tick check showed both commit orders. On PostgreSQL and MariaDB, the manual trigger committed first and the tick skipped the stale occurrence, with one completed workflow. On MySQL, the tick committed first, then the manual request independently started another workflow. Both completed. The different counts reflect ordering, not a backend-specific rule. The raw `*-manual_concurrent-*.json` files record each actor, durable instance, and history row.

The published package failed the tick/backfill overlap check on MySQL, PostgreSQL, and MariaDB: `tick1` started the nominal 01:00 UTC occurrence, then `backfill` attempted it again. The embedded starter rejected the existing instance ID and schedule `failures_count` became 1. See `*-backfill_collision-backfill.json` (MySQL's first collision is in `mysql-backfill_collision-collision.json`). A standalone Server starter creates a fresh workflow ID per start, so the shared schedule layer needs its own occurrence check.

## Candidate fix checks

The candidate migration applied to existing published data on SQLite, MySQL, PostgreSQL, and MariaDB. A retry of the old tick/backfill collision returned `already_fired_occurrence` on each and created no extra start or failure; the old failure count remains visible in the raw files. SQLite's `sqlite-after-migration-old-*.json` files also cover an older writer inserting an unindexed triggered event after the migration. The candidate recognized that event and skipped the repeated occurrence. The three server databases' `*-candidate-concurrent-*.json` files show the due-minute competing-process result after the candidate migration.

These are qualification observations for the PR candidate. A fresh published-artifact rerun is required after release.
