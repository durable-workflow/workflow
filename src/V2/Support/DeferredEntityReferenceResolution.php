<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use RuntimeException;
use Workflow\V2\Enums\ActivityAttemptStatus;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

/** @internal Commits local preparation before repository access; contains no application object. */
final class DeferredEntityReferenceResolution extends RuntimeException
{
    public readonly string $taskId;

    public readonly int $taskAttempt;

    public readonly string $runId;

    public readonly ?string $namespace;

    public readonly string $executionId;

    public readonly string $attemptId;

    private readonly string $argumentsFingerprint;

    public function __construct(WorkflowRun $run, WorkflowTask $task, ActivityExecution $execution, ActivityAttempt $attempt)
    {
        parent::__construct('Local entity resolution requires the committed hosting claim.');
        $this->taskId = $task->id;
        $this->taskAttempt = (int) $task->attempt_count;
        $this->runId = $run->id;
        $this->namespace = $run->namespace;
        $this->executionId = $execution->id;
        $this->attemptId = $attempt->id;
        $this->argumentsFingerprint = self::fingerprint($execution);
    }

    public function matches(WorkflowRun $run, WorkflowTask $task, ActivityExecution $execution, ActivityAttempt $attempt): bool
    {
        return $task->id === $this->taskId && (int) $task->attempt_count === $this->taskAttempt
            && EmbeddedLocalActivityScope::owns($task) && $run->id === $this->runId
            && $run->namespace === $this->namespace && $task->namespace === $run->namespace
            && $run->status instanceof RunStatus && ! $run->status->isTerminal()
            && $task->workflow_run_id === $run->id && $execution->workflow_run_id === $run->id
            && $execution->id === $this->executionId && $attempt->id === $this->attemptId
            && $attempt->workflow_task_id === $task->id && $attempt->lease_owner === $task->lease_owner
            && $attempt->activity_execution_id === $execution->id && $attempt->workflow_run_id === $run->id
            && $execution->current_attempt_id === $attempt->id
            && (int) $execution->attempt_count === (int) $attempt->attempt_number
            && $execution->status === ActivityStatus::Running && $attempt->status === ActivityAttemptStatus::Running
            && $attempt->lease_expires_at !== null && now()->lt($attempt->lease_expires_at)
            && ActivityAttemptAuthority::expiredReason($task, $execution, now(), $run) === null
            && hash_equals($this->argumentsFingerprint, self::fingerprint($execution));
    }

    private static function fingerprint(ActivityExecution $execution): string
    {
        $context = hash_init('sha256');
        foreach ([
            (string) $execution->activity_class,
            (string) $execution->activity_type,
            (string) $execution->payload_codec,
            (string) $execution->arguments,
            json_encode($execution->activity_options[EntityReferenceArguments::POSITIONS] ?? null, JSON_THROW_ON_ERROR),
        ] as $value) {
            hash_update($context, pack('N', strlen($value)) . $value);
        }
        return hash_final($context);
    }
}
