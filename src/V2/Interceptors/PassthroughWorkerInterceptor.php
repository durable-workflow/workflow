<?php

declare(strict_types=1);

namespace Workflow\V2\Interceptors;

/**
 * No-op interceptor to extend when only some hooks are needed.
 */
class PassthroughWorkerInterceptor implements WorkerInterceptor
{
    public function executeWorkflowTask(WorkflowTaskInterceptorContext $context, callable $next): WorkflowTaskOutcome
    {
        return $next($context);
    }

    public function executeActivity(ActivityInterceptorContext $context, callable $next): mixed
    {
        return $next($context);
    }

    public function executeQueryTask(QueryTaskInterceptorContext $context, callable $next): mixed
    {
        return $next($context);
    }
}
