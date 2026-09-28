# Buffered signal admission, 28 September 2026

This is a local diagnostic for [Workflow #565](https://github.com/durable-workflow/workflow/issues/565). It compares source commit `90e5cde0a5eca30982d98c05f7587b08cea0d801` with the bounded buffered-signal path at `27ec271c28e7943a0cb92f2d3b7a64cf94c57b9c`. Both used the same [profile test](../../V2SignalAdmissionProfileTest.php), fixture, vendor tree, and host. The test starts one workflow, fakes queue dispatch, and admits 200 same-name signals without a worker. All signals remain pending for this admission measurement.

## Fixture and environment

- PHP CLI 8.4.26 in `php:8.4-cli-bookworm`, resolved image digest `php@sha256:f1d32fb402fffba0b3dd8ba8c0aca474c9e9f04395fa846eedea77c503257dee`.
- SQLite `:memory:`, database queue, array cache, no container CPU or memory cap.
- Intel Core i5-6500, four CPUs, 15 GiB host memory. Host swap was enabled. These are local PHP process measurements, including Laravel and the test harness.
- Three sequential baseline/candidate pairs, each in a fresh PHP container. All six runs accepted 200 signals, recorded 200 `SignalReceived` events, and ended with 200 pending signals.

| Measure | Baseline, range of 3 | Candidate, range of 3 |
| --- | ---: | ---: |
| 200 admissions, wall | 19.731–19.898 s | 2.418–2.433 s |
| 200 admissions, process CPU | 19.732–19.898 s | 2.420–2.434 s |
| First 50, p50 | 38.739–39.097 ms | 11.818–11.932 ms |
| Last 50, p50 | 158.278–158.608 ms | 11.945–12.071 ms |
| Last 50, p95 | 174.714–175.904 ms | 12.864–13.113 ms |
| Last 50, SQL calls per signal | 57 | 37 |
| PHP peak allocated memory | 74.5 MiB | 60.5 MiB |

The baseline's per-signal latency grew with prior buffered history. The candidate's last 50 stayed close to its first 50 while updating history, run summary, task diagnostics, and timeline. The first signal still uses the full projection path. This fixture measures signal admission in embedded Workflow. Published Server and SDK qualification is tracked separately in [Server #237](https://github.com/durable-workflow/server/issues/237).

## Commands and raw output

From the Workflow checkout, prepare the baseline source with `git archive 90e5cde0 | tar -x -C "$baseline_dir"`, where `baseline_dir` is a new empty directory. The baseline mounted the current checkout's `vendor/` and profile file into that source tree. Each run used:

```sh
docker run --rm --user 1000:1000 --network none \
  -v "$baseline_dir:/workspace" \
  -v "$PWD/vendor:/workspace/vendor:ro" \
  -v "$PWD/tests/Performance/V2SignalAdmissionProfileTest.php:/profile/V2SignalAdmissionProfileTest.php:ro" \
  -w /workspace \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
  -e QUEUE_CONNECTION=database -e CACHE_DRIVER=array -e CACHE_STORE=array \
  php:8.4-cli-bookworm \
  php vendor/bin/phpunit /profile/V2SignalAdmissionProfileTest.php --no-coverage --colors=never
```

For the candidate, mount the current checkout at `/workspace` and run `php vendor/bin/phpunit tests/Performance/V2SignalAdmissionProfileTest.php --no-coverage --colors=never` with the same environment. The raw PHPUnit output and JSON from each run are in the six sibling `.log` files. The profile records p50/p95 over the first and last 50 admissions, process CPU, query calls, peak PHP memory, accepted history, and pending backlog.
