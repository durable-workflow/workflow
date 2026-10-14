# Embedded HTTP example

This optional Laravel application is being qualified for Workflow #747.
It exercises V2 workflows through authenticated application routes and a
separate Laravel queue worker. Measurements and the final recommendation are
pending. The exact package set is in `composer.lock`.

The initial profile uses Linux amd64, SQLite WAL, a database queue and file
cache. It has two HTTP PHP workers, one queue worker and nginx. Their combined
limit is two CPUs and 1 GiB, including SQLite page cache. No separate database
or Redis process is used. The external test driver has its own allocation.

## Run

The credentials and application key below are synthetic local demo values.
The example binds HTTP to loopback.

```sh
export APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=
docker compose build
docker compose up -d
```

Submit work and inspect it with the same user:

```sh
curl -f http://127.0.0.1:8087/api/workflows \
  -H 'Authorization: Bearer qualification-alpha' \
  -H 'Content-Type: application/json' \
  -d '{"instance_id":"alpha-example","mode":"fast"}'

curl -f http://127.0.0.1:8087/api/workflows/alpha-example \
  -H 'Authorization: Bearer qualification-alpha'
```

These are example application endpoints. `qualification-beta` has a separate
namespace and cannot inspect alpha's run. Replace the demo token map with your
own authentication and authorization before adapting it for an application.

Use `timer`, `signal`, `cancel` or `continue` for the other workflow paths.
Signal a waiting workflow with `POST /api/workflows/{instance}/signal`, query it
with `GET /api/workflows/{instance}/query`, or use the explicitly terminal
`POST /api/workflows/{instance}/cancel` operation.

The `cleanup` path waits durably and executes a cleanup activity and timer in
`finally`. Request cooperation with `POST /api/workflows/{instance}/request-cancellation`.
The example fixes the cleanup budget at 30 seconds and exposes the original
command identity. Terminal cancellation is a separate operation.

## HTTP lifecycle

```sh
docker compose exec http-app php artisan octane:reload
docker compose exec http-app php artisan octane:stop
docker compose restart http-app
docker compose restart queue
```

HTTP recycling defaults to 500 requests. The queue worker recycles after
500 jobs and has a 60-second execution timeout with a 65-second stop grace.
The supervisor restarts both processes. Choose an execution timeout that
covers your application's activities.

The HTTP container uses an init process to reap children. Octane receives
`SIGTERM` on shutdown. The FPM override uses `SIGQUIT`, the graceful stop signal
for the base image's FPM process. Keep these signals specific to the selected
HTTP mode when adapting the example.

## Ordinary PHP hosting

```sh
docker compose down
docker compose -f compose.yaml -f compose.fpm.yaml up -d
```

FPM uses the same image, packages, OPcache, configuration cache, worker count
and resource allocation. Use a separate `EXAMPLE_STATE_DIRECTORY` and unique
workflow IDs for an independent comparison.

Storage/model configuration is fixed at process boot. `QUALIFICATION_EAGER`
selects boot-time resolution for the lifecycle tests. Changing it requires
regenerating the configuration cache and restarting the processes.
`EXAMPLE_DB_CONNECTION=qualified_alt` selects an independent named SQLite
connection at process boot. It does not switch shared backend authority during
a request.

To keep the application's queue database separate from workflow storage, set
`EXAMPLE_DB_CONNECTION=qualified` and `EXAMPLE_WORKFLOW_CONNECTION=qualified_alt`
before preparing a fresh stack. Jobs remain in `/state/workflow.sqlite`, while
Workflow models and migrations use `/state/alternate.sqlite`. Regenerate the
configuration cache and restart both HTTP and queue workers after changing this
boot-time setting. Pass both paths to `qualification/database.php` when auditing
this layout.

## Qualification commands

Run the Node drivers from a separate Node container on the Compose network.
Set `BASE_URL=http://nginx:8080`, a unique `PROFILE_NAME`, and mount an empty
writable directory at `/evidence`. `lifecycle.mjs` checks authenticated users,
namespaces, signals, queries, timers, terminal cancellation and continuation.
`qualification/cleanup.mjs` checks cooperative cleanup and duplicate identity.

For the measured workload, run `qualification/benchmark.mjs` with fixed
`OFFERED_RATE`, `DURATION_SECONDS` and `DRAIN_SECONDS`. Set `MIXED=true` to
include durable timers and signal/query paths. It reports offered, accepted,
completed and unfinished counts, HTTP and observed completion percentiles,
HTTP errors and transport failures, query counts and the measured drain. A failed original is not retried by the
driver. Completion latency includes its 250 ms status-poll interval.

Sample `qualification/resources.sh` inside each HTTP, queue and nginx container
throughout the run. It reads Linux cgroup CPU, memory, swap and sampled total
process RSS. Keep cgroup memory and RSS separate. Use `qualification/database.php`
after the driver stops to check original statuses, event counts, automatic repairs,
failed jobs, queue drain and actual timer due-to-workflow-completion latency.
Completion alone does not establish a clean run. Inspect failed jobs and repaired
tasks. The watchdog can redispatch an overdue ready task before its first claim,
so a repair counter alone does not prove a failed or repeated execution. Report
task types and attempt counts alongside repairs, and investigate reclaimed leases,
replay failures and missing tasks before accepting the result.
These commands provide measurements,
not a performance recommendation by themselves.

Stop the stack when finished:

```sh
docker compose down
```

The `data` directory contains this example's database and logs. Keep it while
its workflows are needed, then remove it deliberately. Composer dependencies,
the RoadRunner binary, local data and generated configuration are ignored by Git.
