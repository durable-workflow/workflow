# Published database-queue consumer

This diagnostic prepares an independent reproduction of the embedded fan-out
observations in [#809](https://github.com/durable-workflow/workflow/issues/809).
It installs a published package independently of the source checkout. It does
not load the draft tracing implementation. Admission and the first workflow
delivery have been checked. Full qualification of this portable fixture is
pending; the issue's original cohorts used its preceding preparation.

Run these commands in a disposable PHP 8.5 container with SQLite and Composer:

```sh
composer install --working-dir=.github/queue-contention --no-plugins --no-scripts
php .github/queue-contention/runner.php cohort
DW_PROBE_QUEUE_WAKE=true php .github/queue-contention/runner.php cohort
DW_PROBE_QUEUE_WAKE=true DW_PROBE_CONTINUOUS=true php .github/queue-contention/runner.php cohort
```

For a separately named, unpublished source control, set
`DW_QUALIFICATION_AUTOLOAD` to that source installation's autoloader. The output
labels this override and hashes the loaded dispatcher. That result is not a
normal installed-package result and does not qualify the locked baseline.

The fixture uses four independent queue workers, 64 ordinary activities, a
90-second observer and original execution/run limits of 120/100 seconds.
Workers ordinarily have a 45-second lifecycle. `DW_PROBE_DRAIN_SECONDS=80`
selects a separate lifecycle diagnostic; its process waits are capped by the
remaining original observer time. This corrected observer has not qualified
the earlier control that overran its deadline.

`DW_PROBE_CONTINUOUS=true` keeps all four workers available until the parent
observes canonical completion within the original 90-second budget. They then
stop through the real worker's public Looping event. Initial work, drain and
inspection share that same absolute deadline. This does not qualify SIGTERM.
The default legacy drainer retains its 45-second limit and
`--stop-when-empty`; a transient null poll can end it before the queue drains.

## Current published PHP 8.3 control

The separate `current` manifest selects the published patch and exact framework
versions for the hosted tracing failure. It leaves the original locked input
unchanged. Run:

```sh
composer install --working-dir=.github/queue-contention/current --no-plugins --no-scripts
DW_QUALIFICATION_AUTOLOAD="$PWD/.github/queue-contention/current/vendor/autoload.php" \
DW_PROBE_QUEUE_WAKE=true DW_PROBE_CONTINUOUS=true \
php .github/queue-contention/runner.php cohort
```

The `explicit-autoloader` output describes the selected path. For this control
it points to an ordinary Composer installation, not a runtime source override.
The Published queue consumer workflow verifies every installed `src` byte and
the Composer reference against the immutable release before executing it. It
records actual hosted PHP/SQLite/CPU/memory facts; it does not claim a 2 CPU /
2 GiB allocation or qualify the tracing branch. Earlier failed originals retain
their outcomes. A timeout reports bounded read-only state before cleanup.

Results distinguish unfinished execution, delivery exceptions, repairs,
transport copies, callbacks and preserved history/deadlines. A nonzero exit is
expected for an unsuccessful control. Completion with repairs or failed
deliveries also fails the clean acceptance check. Do not reinterpret that as a
failed workflow if the result says it completed.

Set `DW_PROBE_REPAIR_OBSERVATIONS=true` for bounded task and native-row metadata
before repair updates, at most eight observations per process. Records can
precede a rolled-back transaction. The final repair count reports committed
effects. These diagnostic reads do not grant claim or execution authority.

`business_invariants_pass` reports exact typed completion, callback/attempt
counts and preserved original timing/history. `clean_acceptance_pass` also
requires zero repairs, failed deliveries and leftover jobs. Both remain visible.

Each cohort creates its own temporary SQLite database and removes its workers
and data in `finally`. Remove the installed `vendor` directory and Composer
cache after the phase. This is a synthetic functional diagnostic, not a capacity
or overhead benchmark. The recorded original failures do not qualify later
source changes.
