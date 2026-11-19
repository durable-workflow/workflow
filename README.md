# Durable Workflow for Laravel

<p align="center">
  <a href="https://github.com/durable-workflow/workflow/actions/workflows/php.yml?query=branch%3Amain"><img src="https://github.com/durable-workflow/workflow/actions/workflows/php.yml/badge.svg?branch=main" alt="Build status"></a>
  <a href="https://sonarcloud.io/summary/new_code?id=durable-workflow_workflow"><img src="https://sonarcloud.io/api/project_badges/measure?project=durable-workflow_workflow&amp;metric=alert_status" alt="Quality Gate"></a>
  <a href="https://codecov.io/gh/durable-workflow/workflow/branch/main"><img src="https://codecov.io/gh/durable-workflow/workflow/branch/main/graph/badge.svg" alt="Code coverage"></a>
  <a href="https://packagist.org/packages/durable-workflow/workflow"><img src="https://img.shields.io/packagist/v/durable-workflow/workflow" alt="Latest Packagist version"></a>
  <a href="https://packagist.org/packages/durable-workflow/workflow/stats"><img src="https://img.shields.io/packagist/dt/durable-workflow/workflow" alt="Packagist downloads"></a>
  <a href="https://github.com/durable-workflow/workflow/blob/main/LICENSE"><img src="https://img.shields.io/packagist/l/durable-workflow/workflow" alt="MIT license"></a>
</p>

Durable Workflow is the embedded Laravel runtime for durable execution. Write
long-running workflows as ordinary PHP, keep completed work recorded through
worker restarts and application deploys, and use Laravel's queues, cache, and
database as the runtime.

This package also provides the orchestration engine hosted by
[Durable Workflow Server](https://github.com/durable-workflow/server). Use the
[PHP SDK](https://github.com/durable-workflow/sdk-php) with Server or
[Durable Workflow Cloud](https://cloud.durable-workflow.com/) when workers need
to run outside the Laravel application or across PHP, Python, and Rust.

Current Workflow requires **PHP 8.2 or newer** and **Laravel 12.72.0 or newer
on the 12.x line, or Laravel 13.41.0 or newer on the 13.x line**. Laravel 13
requires PHP 8.3. The [dependency security policy](resources/laravel-dependency-security-policy.json)
records the supported framework floors. Keep Composer audits and application
security updates enabled.

### Upgrade to 2.21

Upgrade PHP 8.1 applications to PHP 8.2 or newer and Laravel 10/11 applications
to patched Laravel 12 or 13 before requiring Workflow 2.21. Composer refuses
2.21 on the retired platforms. Keep the application's existing 2.20.x lock
while preparing that upgrade; older Workflow releases do not extend upstream
security support for PHP or Laravel. Do not disable advisory blocking to force
an upgrade.

This platform change does not migrate durable history. Existing v1 runs remain
owned by v1; retain their code and workers until they finish, and use the
[embedded upgrade contract](resources/laravel-embedded-upgrade-contract.json)
to choose drain or coexistence. The stable 1.x authority is unchanged.

Laravel 9 applications remain on their compatible Workflow 2.16.x line until
they upgrade the framework. Laravel 9 has no fix for the
[TokenGuard advisory](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6)
and was removed in Workflow 2.17.0; see [#922](https://github.com/durable-workflow/workflow/issues/922).

## Install

```bash
composer require durable-workflow/workflow:^2.0
php artisan migrate
```

For a new v2-only application, set `DW_V1_ENABLED=false` before the first
migration. This omits the legacy v1 tables and queue watchdog. Existing
applications keep v1 enabled by default so they can drain older work; do not
disable it until that work is retired. Changing this setting does not drop
existing tables.

Run a Laravel queue worker or Horizon to execute workflows and activities:

```bash
php artisan queue:work
```

For the default database queue on SQLite, failed reservation writes preserve
unclaimed work after a proven rollback. See [SQLite queue reservations](docs/sqlite-queue-reservations.md)
for the supported framework versions and custom-connector boundary.

## Optional tracing and log correlation

Core 2.9.0 can recover bounded W3C context across embedded queue workers and
add run, task, activity, attempt, trace and span identifiers to Laravel logs.
Tracing is disabled by default and uses the application's OpenTelemetry
provider. See [embedded tracing](docs/embedded-tracing.md) for migrations,
configuration, the log tap, exporter limits and measured overhead.

## Your First Workflow

```php
<?php

use Workflow\V2\Activity;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;
use function Workflow\V2\activity;

final class GreetActivity extends Activity
{
    public function handle(string $name): string
    {
        return "Hello, {$name}!";
    }
}

final class GreetWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        return activity(GreetActivity::class, $name);
    }
}

$workflow = WorkflowStub::make(GreetWorkflow::class);
$workflow->start('world');

echo $workflow->output(); // Hello, world!
```

Workflow code can coordinate activities, timers, signals, queries, updates,
child workflows, sagas, cancellation, retries, parallel work, side effects,
continue-as-new, search attributes, memo, and message streams. The runtime
persists execution history so replay can resume after process or host failure
without repeating completed activities.

## Schedule a Workflow

```php
use Workflow\V2\Support\ScheduleManager;

$schedule = ScheduleManager::create(
    scheduleId: 'daily-greeting',
    workflowClass: GreetWorkflow::class,
    cronExpression: '0 9 * * *',
    arguments: ['world'],
    executionTimeoutSeconds: 120,
    runTimeoutSeconds: 60,
);
```

Run `php artisan workflow:v2:schedule-tick` from Laravel's scheduler to start
due occurrences. Both scheduled occurrences and `ScheduleManager::trigger()`
use these limits. The execution timeout covers one logical execution across
retries and continue-as-new. The run timeout limits each individual run and
resets on continue-as-new. Each occurrence starts a new execution budget.
Omit either option, or pass `null`, to leave that limit unset.

See [schedule timeout configuration](docs/schedules.md) for the action format,
validation and updates.

## Choose a Deployment

| Deployment | Use it when | Runtime owner |
| --- | --- | --- |
| Embedded Laravel | Workflows and activities live inside one Laravel application. | Your application owns persistence and queue execution. |
| Self-hosted Server | Workers run independently or in multiple languages. | Your team operates Server, MySQL, Redis, and optional Waterline. |
| Durable Workflow Cloud | You want a managed runtime for PHP, Python, and Rust workers. | Durable Workflow operates the runtime and persistence. |

Embedded runs remain owned by the Laravel application. Moving new work to
Server or Cloud does not reinterpret existing embedded history.

## Learn More

- [Embedded installation](https://durable-workflow.com/docs/2.0/installation/)
- [Embedded feature guides](https://durable-workflow.com/docs/2.0/category/embedded/)
- [Embedded live execution](docs/workflow/embedded-live-execution.md)
- [Embedded activity leases](docs/workflow/embedded-activity-leases.md)
- [Configuration reference](https://durable-workflow.com/docs/2.0/configuration/options/)
- [Deployment modes](https://durable-workflow.com/docs/2.0/polyglot/deployment-modes/)
- [Monitoring with Waterline](https://durable-workflow.com/docs/2.0/monitoring/)
- [Runnable Sample App](https://github.com/durable-workflow/sample-app)

Questions and design discussions are welcome in
[GitHub Discussions](https://github.com/durable-workflow/workflow/discussions)
and [Discord](https://discord.gg/xu5aDDpqVy).

## Sponsors

Durable Workflow is sustained by contributors and sponsors:

- [Freispace Resource Scheduling](https://freispace.com)
- [Translate a Book](https://translateabook.com)
