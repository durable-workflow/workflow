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

Stop the stack when finished:

```sh
docker compose down
```

The `data` directory contains this example's database and logs. Keep it while
its workflows are needed, then remove it deliberately. Composer dependencies,
the RoadRunner binary, local data and generated configuration are ignored by Git.
