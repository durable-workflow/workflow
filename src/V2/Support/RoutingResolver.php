<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Models\WorkflowRun;
use Workflow\WorkflowMetadata;

final class RoutingResolver
{
    /**
     * @param class-string $workflow
     */
    public static function workflowConnection(string $workflow, WorkflowMetadata $metadata): ?string
    {
        $connection = $metadata->options->connection;

        if ($connection !== null) {
            return $connection;
        }

        $defaults = DefaultPropertyCache::for($workflow);

        if (isset($defaults['connection']) && is_string($defaults['connection']) && $defaults['connection'] !== '') {
            return $defaults['connection'];
        }

        $configured = config('workflows.v2.connection');

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * @param class-string $workflow
     */
    public static function workflowQueue(string $workflow, WorkflowMetadata $metadata): ?string
    {
        $queue = $metadata->options->queue;

        if ($queue !== null) {
            return $queue;
        }

        $defaults = DefaultPropertyCache::for($workflow);

        if (isset($defaults['queue']) && is_string($defaults['queue']) && $defaults['queue'] !== '') {
            return $defaults['queue'];
        }

        $configured = config('workflows.v2.queue');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $connection = self::workflowConnection($workflow, $metadata) ?? config('queue.default');

        return config('queue.connections.' . $connection . '.queue', 'default');
    }

    /**
     * @param class-string $activity
     */
    public static function activityConnection(
        string $activity,
        WorkflowRun $run,
        ?ActivityOptions $options = null
    ): ?string {
        if ($options?->connection !== null) {
            return $options->connection;
        }

        if ($options?->workerSession?->connection !== null) {
            return $options->workerSession->connection;
        }

        $defaults = DefaultPropertyCache::for($activity);

        if (isset($defaults['connection']) && is_string($defaults['connection']) && $defaults['connection'] !== '') {
            return $defaults['connection'];
        }

        return $run->connection ?? config('queue.default');
    }

    /**
     * @param class-string $activity
     */
    public static function activityQueue(string $activity, WorkflowRun $run, ?ActivityOptions $options = null): ?string
    {
        if ($options?->queue !== null) {
            return $options->queue;
        }

        if ($options?->workerSession?->queue !== null) {
            return $options->workerSession->queue;
        }

        $defaults = DefaultPropertyCache::for($activity);

        if (isset($defaults['queue']) && is_string($defaults['queue']) && $defaults['queue'] !== '') {
            return $defaults['queue'];
        }

        $connection = self::activityConnection($activity, $run, $options) ?? config('queue.default');

        return $run->queue
            ?? config('queue.connections.' . $connection . '.queue', 'default');
    }
}
