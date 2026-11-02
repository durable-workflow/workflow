# Executor CPU observer and drift controls, October 20, 2026

[Run 39462885370](https://github.com/durable-workflow/workflow/actions/runs/39462885370) passes eighteen fresh 100-event originals on source `92bb602fb7479835ad4e4b7ecd67c5a01de80542`. This precedes the configured-model identity correction in `9ee8d641`; it is not final-source or published-package qualification. Each repetition keeps one host, image and lock across three observer blocks, with both replay modes in a fresh container in each block. Orders are on/off/on for repetitions 1 and 3, off/on/off for repetition 2. Replay/retained order is also reversed in repetition 2. No original is resumed or rebudgeted.

All eighteen pass exact source/lock and ordinary workload audits: 1,800 total history events, 342 activity effects, original identities/prefixes/deadlines, recorded clocks/typed outputs, first-attempt tasks, zero task-maintenance transitions, no failed jobs/unfinished work, empty queues and successful worker drain. One CPU / 1 GiB aggregate memory / no swap and the original per-mode 3,600-second bounds remain unchanged. No container is OOM-killed or restarted. PHP 8.5.6, Laravel 13.38.0, SQLite 3.51.2; input lock SHA-256 `0e41dd1ce1043d7ab1493a9916f219c571a9b931cc4f448d0b73da2b535eef8d`.

## Observer cost and measured drift

CPU values are aggregate queue-worker seconds. The on/off estimate compares the central block with the mean of its two repeated-setting bookends, within the same host and replay mode. Drift is `(last repeated CPU / first repeated CPU - 1) * 100`. The estimate is always expressed as observer-on relative to observer-off.

| Repetition | Mode | Observer order | CPU block 1 / 2 / 3 seconds | On vs off estimate | Repeated-setting drift |
|---|---|---|---:|---:|---:|
| 1 | replay | on / off / on | 12.051007 / 12.101955 / 12.882718 | +3.02% | +6.90% |
| 1 | retained | on / off / on | 11.258804 / 11.650038 / 11.971524 | -0.30% | +6.33% |
| 2 | replay | off / on / off | 13.236511 / 13.104947 / 13.015287 | -0.16% | -1.67% |
| 2 | retained | off / on / off | 12.424036 / 12.250443 / 12.183669 | -0.43% | -1.93% |
| 3 | replay | on / off / on | 13.613863 / 13.459163 / 13.654846 | +1.30% | +0.30% |
| 3 | retained | on / off / on | 12.707790 / 12.549586 / 12.674747 | +1.13% | -0.26% |

The third host observes a roughly 1.1–1.3% on/off CPU increase, larger than its roughly 0.3% repeated-setting drift. The other hosts include negative estimates and drift up to 6.9%. These observations do not establish one portable overhead percentage or correction factor. Optional clock/listener overhead remains in all observer-on phase values. The off control still retains source/lock checks, business audits, maintenance and authoring counters, normal cache diagnostics and process CPU/memory readings; it is not an uninstrumented production baseline.

The separately dispatched source-`6f087ee6` observer-on study [39461324018](https://github.com/durable-workflow/workflow/actions/runs/39461324018) also passes six originals. Its observer-off companion39461327140 was canceled while all jobs had zero steps, before any original ran. Different-host on/off jobs are not treated as paired observer-overhead evidence. Older reports and rejected observations are unchanged.

## Actual phase attribution

The following deterministic selection uses the first observer-on block in each repetition (block 1, 2, 1). CPU is seconds. All phase columns are exclusive of nested observed phases. Authoring sums actual Fiber start/resume/throw CPU between execution and suspension/return/throw; it includes workflow API calls and forward execution as well as replay. It is not isolated completed-prefix CPU. History/projection includes that role's SQL/history work. Initial loading does not represent every read in the task. Remaining CPU includes unclassified worker, claim/transaction, activity/transport and observer work. Inclusive buckets in the raw report overlap and must not be added together.

| Repetition / mode | Worker | History / projection | Initial load | Initialization | Step validation | Durable dispatch | Authoring | Remaining |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| 1 / replay | 12.051007 | 9.255817 | 0.145473 | 0.016947 | 0.047312 | 1.289439 | 0.025453 | 1.270566 |
| 1 / retained | 11.258804 | 9.254205 | 0.149825 | 0.077162 | 0.008702 | 0.424107 | 0.002992 | 1.341811 |
| 2 / replay | 13.104947 | 10.068025 | 0.154315 | 0.017848 | 0.052924 | 1.423422 | 0.027538 | 1.360875 |
| 2 / retained | 12.250443 | 10.106109 | 0.158573 | 0.081560 | 0.010088 | 0.463752 | 0.003220 | 1.427141 |
| 3 / replay | 13.613863 | 10.455567 | 0.162167 | 0.018263 | 0.054814 | 1.485308 | 0.028428 | 1.409316 |
| 3 / retained | 12.707790 | 10.471219 | 0.163966 | 0.085761 | 0.010386 | 0.478679 | 0.003387 | 1.494392 |

In these small-history cells, history/projection remains the largest measured CPU component. Retention avoids completed-prefix starts and some recorded-outcome dispatch/validation work, while adding cache/history validation. The authored PHP portion is small; do not describe the whole worker-CPU difference as authored-code CPU. The [1,000-event pilot](GROWTH-1000-PILOT.md) motivates repeated larger-history qualification but does not replace this phase measurement.

## Exact per-host receipts

| Repetition | CPU model | Built image |
|---|---|---|
| 1 | INTEL(R) XEON(R) PLATINUM 8573C | `sha256:9e5fddac948e3f8d7b9b2b18d1f393412e8edcad0514c289ae7030fb7c262690` |
| 2 | INTEL(R) XEON(R) PLATINUM 8573C | `sha256:f6e54e4ad2fa2271d8785f931fbdc8ddbdd77c535d1de4db9ccb5dfa559236be` |
| 3 | INTEL(R) XEON(R) PLATINUM 8573C | `sha256:6bf176439724415e8487be66c030d7dce23a24ea642338319c0c49001eae03d7` |

The scalar Actions reports retain every original, block order, cache counters, SQL and history SELECT counts/elapsed, task/timer delay distributions, source hashes, cold test-worker startup, CPU/RSS/cgroup peak and exit evidence. No database/history archive is published.
