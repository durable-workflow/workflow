<p align="center"><a href="https://github.com/durable-workflow/workflow/actions/workflows/php.yml"><img src="https://img.shields.io/github/actions/workflow/status/durable-workflow/workflow/php.yml" alt="GitHub Workflow Status"></a> <a href="https://codecov.io/gh/durable-workflow/workflow"><img alt="Codecov" src="https://img.shields.io/codecov/c/github/durable-workflow/workflow"></a> <a href="https://packagist.org/packages/laravel-workflow/laravel-workflow/stats"><img alt="Packagist Downloads (custom server)" src="https://img.shields.io/packagist/dt/laravel-workflow/laravel-workflow"></a>
 <a href="https://durable-workflow.com/docs/installation"><img src="https://img.shields.io/badge/docs-read%20now-brightgreen" alt="Docs"></a> <a href="https://github.com/durable-workflow/workflow/blob/master/LICENSE"><img alt="Packagist License" src="https://img.shields.io/packagist/l/laravel-workflow/laravel-workflow?color=bright-green"></a></p>

Durable Workflow (formerly Laravel Workflow) is a package for the Laravel web framework that provides tools for defining and managing workflows and activities. A workflow is a series of interconnected activities that are executed in a specific order to achieve a desired result. Activities are individual tasks or pieces of logic that are executed as part of a workflow.

Durable Workflow can be used to automate and manage complex processes, such as agentic workflows (AI-driven), financial transactions, data analysis, data pipelines, microservices, job tracking, user signup flows, sagas and other business processes. By using Durable Workflow, developers can break down large, complex processes into smaller, modular units that can be easily maintained and updated.

Some key features and benefits of Durable Workflow include:

- Support for defining workflows and activities using simple, declarative PHP classes.
- Tools for starting, monitoring, and managing workflows, including support for queuing and parallel execution.
- Built-in support for handling errors and retries, ensuring that workflows are executed reliably and consistently.
- Integration with Laravel's queue and event systems, allowing workflows to be executed asynchronously on worker servers.
- Extensive documentation and a growing community of developers who use and contribute to Durable Workflow.

## Documentation

Documentation for Durable Workflow can be found on the [website](https://durable-workflow.com/docs/installation).

## Community

You can find us in the [GitHub discussions](https://github.com/durable-workflow/workflow/discussions) and also on our [Discord channel](https://discord.gg/xu5aDDpqVy).

## Sample App

There's also a [sample application](https://github.com/durable-workflow/sample-app) that you can run directly from GitHub in your browser.

## Usage

**1. Create a workflow**
```php
use function Workflow\V2\activity;
use Workflow\V2\Workflow;

class MyWorkflow extends Workflow
{
    public function handle(string $name): string
    {
        $result = activity(MyActivity::class, $name);

        return $result;
    }
}
```

**2. Create an activity**
```php
use Workflow\V2\Activity;

class MyActivity extends Activity
{
    public function handle(string $name): string
    {
        return "Hello, {$name}!";
    }
}
```

**3. Run the workflow**
```php
use Workflow\V2\WorkflowStub;

$workflow = WorkflowStub::make(MyWorkflow::class);
$workflow->start('world');
```

```php
$workflow->output();
=> 'Hello, world!'
```

## Sponsors

The Durable Workflow package is sustained by the community via sponsors and volunteers.

- <a href="https://freispace.com" target="_blank" rel="noopener sponsored">Freispace Resource Scheduling</a>
- <a href="https://github.com/hnccox" target="_blank" rel="noopener sponsored">Hugo Cox</a>

## Monitoring

[Waterline](https://github.com/durable-workflow/waterline) is a separate UI that works nicely alongside Horizon. Think of Waterline as being to workflows what Horizon is to queues.

### Dashboard View

![waterline_dashboard](https://github.com/user-attachments/assets/5688a234-4c02-4d5e-84d4-5f40b5fa27c5)

### Workflow View

![workflow](https://github.com/user-attachments/assets/da685466-7747-4c2f-ae10-300041381d51)

Refer to https://github.com/durable-workflow/waterline for installation and configuration instructions.

<sub><sup>"Laravel" is a registered trademark of Taylor Otwell. This project is not affiliated, associated, endorsed, or sponsored by Taylor Otwell, nor has it been reviewed, tested, or certified by Taylor Otwell. The use of the trademark "Laravel" is for informational and descriptive purposes only. Durable Workflow is not officially related to the Laravel trademark or Taylor Otwell.</sup></sub>
