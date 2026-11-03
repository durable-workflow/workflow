# Legacy overlap release delay

Applications using `Workflow\Workflow` can back off queue jobs that cannot
acquire an overlap lock:

```php
use Workflow\WorkflowOptions;
use Workflow\WorkflowStub;

$workflow = WorkflowStub::make(MyWorkflow::class);
$workflow->start('argument', new WorkflowOptions(overlapReleaseAfter: 5));
```

`WorkflowOptions::set(['overlapReleaseAfter' => 5])` accepts the same setting.
The delay is a nonnegative integer in seconds. Its default is zero, preserving
existing behavior and stored options. It applies to contended workflow,
activity, signal and child-completion queue jobs. It does not change lock
expiry, semaphore ownership, or activity exception backoff.

The setting is saved with the run. Children and continue-as-new inherit it
when no explicit `WorkflowOptions` is supplied. An explicit options object
replaces inherited options, including resetting this delay to zero if omitted.

Laravel counts queue reservations as attempts even when middleware releases
the job before its handler executes. A positive delay reduces rapid retries;
it does not make a low `$tries`, `--tries`, or database queue attempt limit
safe for arbitrary contention. Choose the delay and retry budget for the
expected lock duration. Keep queue visibility and worker timeout settings
consistent with activity duration.

This option belongs to the legacy API. `Workflow\V2\Workflow` uses durable
task claims and does not use this overlap middleware.
