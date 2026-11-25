<?php

declare(strict_types=1);

namespace Workflow\V2\Interceptors;

/**
 * Wraps embedded task execution for audit logging, metrics, tracing and policy hooks.
 *
 * Interceptors listed in `workflows.v2.interceptors` run in that order. The
 * first one is the outermost wrapper, and each hook must call `$next` to
 * continue the chain. Extend {@see PassthroughWorkerInterceptor} to
 * implement only some hooks.
 *
 * Workflow-task hooks observe the task; they cannot change it. The engine
 * always commits the history the workflow code produced and reports the
 * workflow code's own failure, whatever the hook returns.
 */
interface WorkerInterceptor
{
    /**
     * Wrap one workflow task: the replay and run of workflow code that records
     * the task's history.
     *
     * The hook runs once per task attempt, inside the task transaction. The
     * outcome lists only what this task appends, even when
     * {@see WorkflowTaskInterceptorContext::$replaying} shows the code first
     * re-ran a committed prefix. Side effects can repeat when an attempt is
     * retried, so defer them until commit. Throwing rolls the task back, so
     * nothing is recorded, and the task is retried.
     *
     * @param callable(WorkflowTaskInterceptorContext): WorkflowTaskOutcome $next
     */
    public function executeWorkflowTask(WorkflowTaskInterceptorContext $context, callable $next): WorkflowTaskOutcome;

    /**
     * Wrap one activity call, queued or local. The value returned becomes the activity result.
     *
     * @param callable(ActivityInterceptorContext): mixed $next
     */
    public function executeActivity(ActivityInterceptorContext $context, callable $next): mixed;

    /**
     * Wrap one query: the replay of committed state and the query method call.
     * The value returned becomes the query result.
     *
     * @param callable(QueryTaskInterceptorContext): mixed $next
     */
    public function executeQueryTask(QueryTaskInterceptorContext $context, callable $next): mixed;
}
