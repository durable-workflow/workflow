# Optional Octane HTTP hosting

Octane keeps the Laravel HTTP application booted between requests. Durable
workflow execution runs in a separately supervised Laravel queue worker.
The database owns histories, activity outcomes and timer deadlines.

The [embedded HTTP example](../examples/embedded-http/README.md) compares
RoadRunner with nginx/PHP-FPM using the same application and dependencies.
The [measured application profile](embedded-octane-measurements.md) reports
three pairs, a drift control, lifecycle soak and failed upper-load originals.
It measured lower median HTTP latency and a larger memory footprint.
Workflow completion did not improve consistently.

## Application boundaries

Keep storage connections, configured models and backend authority fixed for
each process. Pass an authenticated namespace to workflow operations. Resolve
request data inside the current request, including in services resolved at
boot. Clear request-owned logging fields when a request ends.

The example uses synthetic users and application-owned API routes for start,
status, result, signal, query and terminal cancellation. Its V2 workflows use
a configured model subclass and a named SQLite connection. Replace its demo
authentication with your application's authorization policy.

## Process lifecycle

Configure HTTP worker counts and recycling separately from the queue worker.
Use `octane:reload` after an HTTP deployment and let the queue supervisor replace
execution workers. A process restart leaves pending durable work in the
database. Keep the original deadlines and recover through ordinary replay.

Use Laravel's [Octane installation and lifecycle commands](https://laravel.com/docs/12.x/octane)
for HTTP hosting. Keep durable timers on the Workflow runtime and run the
application's configured queues with `queue:work` or Horizon.

Service-mode PHP applications have a separate profile in
[the PHP SDK HTTP qualification](https://github.com/durable-workflow/sdk-php/issues/112).
