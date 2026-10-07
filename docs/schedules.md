# PHP workflow schedule timeouts

Use the public `Workflow\V2\Support\ScheduleManager` API with the default PHP
workflow starter. `create()` accepts `executionTimeoutSeconds` and
`runTimeoutSeconds` as optional named arguments. Each value must be a positive
integer number of seconds or `null`, matching `Workflow\V2\StartOptions`.

The execution timeout covers the logical workflow execution across retries and
continue-as-new. The run timeout covers an individual run and resets on
continue-as-new. A run cannot exceed the remaining execution budget. Each new
scheduled occurrence has its own execution budget, measured from its actual
workflow start rather than the scheduled fire time.

For interval or multi-cron schedules, use the supported action fields:

```php
use Workflow\V2\Support\ScheduleManager;

$schedule = ScheduleManager::createFromSpec(
    scheduleId: 'invoice-batch',
    spec: ['intervals' => [['every' => 'PT1H']]],
    action: [
        'workflow_class' => InvoiceWorkflow::class,
        'input' => ['hourly'],
        'execution_timeout_seconds' => 120,
        'run_timeout_seconds' => 60,
    ],
);
```

Manual triggers, automatic occurrences, buffered starts and backfills use the
stored action. Omitted or `null` fields leave the corresponding timeout unset.
PHP schedule creation and action updates validate the fields before storing
them. Zero and negative values raise `LogicException`. Non-integer values raise
`TypeError`, matching direct starts in strict PHP code.

Update the action to change subsequent starts:

```php
$schedule = ScheduleManager::update($schedule, action: [
    ...$schedule->action,
    'execution_timeout_seconds' => 240,
    'run_timeout_seconds' => 90,
]);
```

An action update replaces the stored action, so preserve its workflow class and
input as shown. Previously started runs keep their original deadlines. Set a
timeout field to `null` to remove that limit from future starts. No custom
`ScheduleWorkflowStarter` implementation is needed.
