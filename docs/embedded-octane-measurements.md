# Embedded Octane measurements

Measured October 14, 2026. The [optional application recipe](../examples/embedded-http/README.md)
uses authenticated application routes and separately supervised durable queue workers.
This is a small embedded Laravel application comparison.

## Frozen setup

Workflow 2.5.19 (`d4bc406274b3138dea10d9f1ea23e7c1e1bcfc00`),
Laravel 12.69.3, PHP 8.3.35, Octane 2.21.0 and RoadRunner 2025.1.15.
The application lock is in the example. The driver was Node 22 Alpine,
image `sha256:0a7108bf6c7bf5de370ffb1a3ed6be93d405b43ff159f681a8d18c0e2bc2e402`.
Both modes use the same image, OPcache/config cache, two HTTP workers,
one CLI queue worker, nginx, SQLite WAL, database queue and file cache.

The aggregate application allowance is two CPUs and 1 GiB with no swap:
HTTP 0.9 CPU/640 MiB, queue 1 CPU/320 MiB, nginx 0.1 CPU/64 MiB.
SQLite memory and IO are included. There is no separate database or Redis host.
The external Node driver has 0.5 CPU/256 MiB and is excluded from that allowance.

HTTP workers recycle after 500 requests. Queue recycling was frozen at 5,000 jobs
for the short measurements to retain continuous cgroup counters. The lifecycle
soak separately uses the recipe's normal 500-job limit.

## Three pairs and drift check

Each independent run offers 60 mixed workflows at one/sec for 60 seconds,
with a maximum 30-second drain. Three fifths are immediate activity workflows,
one fifth has a two-second durable timer, and one fifth uses a query and signal.
The driver reads status every 250 ms and verifies the same original run and
exact integer/null/Unicode result. Pair order alternates as shown.

| Run | Complete / offered | Start p50 (ms) | Status p50 (ms) | Workflow p50 / p95 / p99 (s) |
| --- | --- | --- | --- | --- |
| fpm-low1 | 60/60 | 59.9 | 14.5 | 1.532 / 6.542 / 10.086 |
| octane-low1 | 60/60 | 50.0 | 9.2 | 1.340 / 4.327 / 6.775 |
| octane-low2 | 60/60 | 51.3 | 9.2 | 1.329 / 5.031 / 5.375 |
| fpm-low2 | 60/60 | 60.0 | 14.4 | 1.397 / 4.738 / 5.192 |
| fpm-low3 | 60/60 | 64.5 | 17.3 | 1.610 / 6.330 / 8.792 |
| octane-low3 | 60/60 | 53.1 | 10.9 | 1.806 / 9.240 / 10.827 |
| fpm-drift | 60/60 | 59.5 | 16.1 | 1.423 / 4.274 / 4.980 |

All seven measurements finished every offered workflow, with zero HTTP or transport
errors, failed queue jobs and final backlog. All task families have maximum
attempt count one. Ready-task redispatch counts were FPM 2/0/2, Octane 0/0/9,
and drift 0. These counters are reported separately from repeated execution.

Octane's median admission and status latency is lower in all three pairs.
Workflow latency has no consistent improvement: the third Octane pair has
slower workflow tails. The final FPM drift result also shows substantial
variation in the tails. Timers, queue scheduling and workflow replay remain
separate from HTTP application boot.

## CPU, memory and database costs

| Run | Complete / offered | Workflow p50 / p95 / p99 (s) | Start / status p50 (ms) | Sampled CPU s / completion | End / peak RSS upper bound (MiB) | HTTP DB / history queries | Queue DB / history queries over process lifetime | Ready redispatches |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| fpm-low1 | 60/60 | 1.532 / 6.542 / 10.086 | 59.9 / 14.5 | 0.472 | 215.0 / 217.9 | 6048 / 386 | 23520 / 1583 | 2 |
| octane-low1 | 60/60 | 1.340 / 4.327 / 6.775 | 50.0 / 9.2 | 0.404 | 417.3 / 419.9 | 5772 / 394 | 23886 / 1600 | 0 |
| octane-low2 | 60/60 | 1.329 / 5.031 / 5.375 | 51.3 / 9.2 | 0.401 | 426.5 / 428.3 | 5611 / 389 | 23726 / 1592 | 0 |
| fpm-low2 | 60/60 | 1.397 / 4.738 / 5.192 | 60.0 / 14.4 | 0.444 | 226.8 / 236.1 | 5681 / 391 | 23813 / 1596 | 0 |
| fpm-low3 | 60/60 | 1.610 / 6.330 / 8.792 | 64.5 / 17.3 | 0.473 | 214.4 / 218.3 | 6065 / 390 | 23701 / 1591 | 2 |
| octane-low3 | 60/60 | 1.806 / 9.240 / 10.827 | 53.1 / 10.9 | 0.444 | 420.9 / 423.8 | 6662 / 389 | 23993 / 1596 | 9 |
| fpm-drift | 60/60 | 1.423 / 4.274 / 4.980 | 59.5 / 16.1 | 0.449 | 224.3 / 226.9 | 5744 / 396 | 24087 / 1608 | 0 |

CPU is the sum of the three cgroup counter deltas divided by observed completions.
Its sampled window includes instrumentation and brief idle time around the driver.
Samples are approximately two seconds apart. End RSS is the sum of final
process-RSS snapshots. Peak RSS is the conservative sum of each role's sampled
maximum, rather than an atomic simultaneous peak. Shared pages can be counted
more than once in RSS. These are different from cgroup memory.

Sampled cgroup-memory upper bounds for the three roles combined were approximately
117–119 MiB for FPM and 273–276 MiB for Octane. Kernel `memory.peak` was unavailable.
No swapping, OOM or application-container restart occurred during the short pairs.
Cold readiness, excluding dependency installation/migration, was
2.536/2.770/2.779s for FPM, 2.506/2.580/2.692s for Octane and 2.360s for drift.

HTTP query counts come from response instrumentation. Queue counts cover the
actual queue process lifetime, including idle polls and its brief startup/shutdown
period. They are not pure per-request query counts. Faster completion changes
the number of status polls. A lower count does not establish retained workflow
history or an execution-engine optimization.

## Reload and recycling soak

A separate five-minute Octane run offered 300 originals at one/sec with the same
30-second drain and application allocation. It selected the normal 500-request
HTTP and 500-job queue limits before admission. Standard HTTP reloads ran at
90 and 180 seconds. Ten distinct HTTP PHP PIDs served the original requests.
The queue container recycled twice, with both original exits zero.

All 300 exact typed outputs passed. The database independently contains 300
activity completions, 300 workflow completions, 60 timer schedules/fires and
60 signals applied. Every task family has maximum attempt count one.
Ten ready-task redispatches remain explicit. Final backlog and failed jobs are zero.

A read-only queue observer inside the allocated queue container supplied 147
samples, without query errors. Sampled maximum backlog was 11 jobs and oldest
ready-job age was seven seconds. Sixty original timer deadlines were matched to
their actual caller completion observations: due-to-observation p50 1.187s,
p95 2.725s, p99 5.469s. The wall-clock association has millisecond precision
and includes status polling.

In the last minute, sampled median RSS was about 321 MiB HTTP, 80 MiB queue
and 11 MiB nginx. Conservative sums are about 412 MiB median and 456 MiB peak.
Cgroup memory is separate: role medians sum to about 272 MiB and sampled
role maxima to about 287 MiB. There was no swap or OOM. Queue recycling resets
its cgroup CPU counters, so this soak has no complete CPU-per-completion claim.
Five minutes is a bounded lifecycle check, not long-term memory qualification.

## Upper-load failures

The first shared-SQLite comparison offered 300 workflows at five/sec for
60 seconds plus the original 30-second drain. Both modes failed this envelope.

| Mode | Acknowledged starts | Caller-observed completions | Stopped canonical completions | Final queue jobs |
| --- | --- | --- | --- | --- |
| FPM | 297 | 24 | 48 | 419 |
| Octane | 298 | 51 | 67 | 379 |

nginx repeatedly exhausted its 256-connection allowance. Octane also recorded
SQLite lock refusals during queue reservation, summary updates and redispatch.
Neither mode had an OOM. Their successful-subset percentiles do not support a
speedup claim. These originals were not rescued or substituted with the lower-load
runs. Changing topology or polling is a separate comparison.

## Recommendation

Use Octane when reduced HTTP boot cost is useful and the application can support
its persistent request lifecycle and larger memory footprint. This example
measured lower median HTTP latency and modest sampled CPU savings. It did not
establish reliably faster workflow completion or higher safe workflow capacity.

Keep durable execution in independently supervised queue workers, use persistent
database timers, and qualify the application's authentication, boot-time services,
configured connections, deployment/reload and worker-recovery paths. The ordinary
FPM recipe remains supported.

[Qualification discussion](https://github.com/durable-workflow/workflow/issues/747).
The service-mode PHP SDK has its separate [HTTP profile](https://github.com/durable-workflow/sdk-php/issues/112).
