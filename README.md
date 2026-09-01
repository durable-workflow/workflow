# Durable Workflow for Laravel

<p align="center">
  <a href="https://github.com/durable-workflow/workflow/actions/workflows/php.yml?query=branch%3Av2"><img src="https://github.com/durable-workflow/workflow/actions/workflows/php.yml/badge.svg?branch=v2" alt="Build status"></a>
  <a href="https://codecov.io/gh/durable-workflow/workflow/branch/v2"><img src="https://codecov.io/gh/durable-workflow/workflow/branch/v2/graph/badge.svg" alt="Code coverage"></a>
  <a href="https://packagist.org/packages/durable-workflow/workflow"><img src="https://img.shields.io/packagist/v/durable-workflow/workflow" alt="Latest Packagist version"></a>
  <a href="https://packagist.org/packages/durable-workflow/workflow/stats"><img src="https://img.shields.io/packagist/dt/durable-workflow/workflow" alt="Packagist downloads"></a>
  <a href="https://github.com/durable-workflow/workflow/blob/v2/LICENSE"><img src="https://img.shields.io/packagist/l/durable-workflow/workflow" alt="MIT license"></a>
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

## Install

```bash
composer require durable-workflow/workflow:^2.0
php artisan migrate
```

Run a Laravel queue worker or Horizon to execute workflows and activities:

```bash
php artisan queue:work
```

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
- [Configuration reference](https://durable-workflow.com/docs/2.0/configuration/options/)
- [Deployment modes](https://durable-workflow.com/docs/2.0/polyglot/deployment-modes/)
- [Monitoring with Waterline](https://durable-workflow.com/docs/2.0/monitoring/)
- [Runnable Sample App](https://github.com/durable-workflow/sample-app)

Questions and design discussions are welcome in
[GitHub Discussions](https://github.com/durable-workflow/workflow/discussions)
and [Discord](https://discord.gg/xu5aDDpqVy).

## Sponsors

Durable Workflow is sustained by contributors and sponsors:

- [Andriy Karpishyn](https://github.com/discovery-ukraine)
- [Freispace Resource Scheduling](https://freispace.com)
- [Translate a Book](https://translateabook.com)
