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
```

The fixture uses four independent queue workers, 64 ordinary activities, a
90-second observer and original execution/run limits of 120/100 seconds.
Workers ordinarily have a 45-second lifecycle. `DW_PROBE_DRAIN_SECONDS=80`
selects a separate lifecycle diagnostic; its process waits are capped by the
remaining original observer time. This corrected observer has not qualified
the earlier control that overran its deadline.

Results distinguish unfinished execution, delivery exceptions, repairs,
transport copies, callbacks and preserved history/deadlines. A nonzero exit is
expected for an unsuccessful control. Completion with repairs or failed
deliveries also fails the clean acceptance check. Do not reinterpret that as a
failed workflow if the result says it completed.

Each cohort creates its own temporary SQLite database and removes its workers
and data in `finally`. Remove the installed `vendor` directory and Composer
cache after the phase. This is a synthetic functional diagnostic, not a capacity
or overhead benchmark. The recorded original failures do not qualify later
source changes.
