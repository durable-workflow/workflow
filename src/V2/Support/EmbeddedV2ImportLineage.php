<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

/** @internal Checks related identities before import and under its write locks. */
final class EmbeddedV2ImportLineage
{
    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $workflow
     * @return list<array{rule: string, message: string}>
     */
    public static function errors(array $bundle, array $workflow, bool $lock = false): array
    {
        $errors = [];
        foreach (self::references($bundle, $workflow) as [$runId, $instanceId]) {
            [, $error] = self::endpoint($workflow['namespace'] ?? null, $runId, $instanceId, $lock);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    public static function existingEndpoint(?string $namespace, string $runId, string $instanceId): ?WorkflowRun
    {
        [$run, $error] = self::endpoint($namespace, $runId, $instanceId, true);
        if ($error !== null) {
            throw new InvalidArgumentException($error['message']);
        }

        return $run;
    }

    /**
     * @return array{WorkflowRun|null, array{rule: string, message: string}|null}
     */
    private static function endpoint(?string $namespace, string $runId, ?string $instanceId, bool $lock): array
    {
        $runs = WorkflowRun::query();
        $instances = WorkflowInstance::query();
        if ($lock) {
            $runs->lockForUpdate();
            $instances->lockForUpdate();
        }
        $run = $runs->find($runId, ['id', 'namespace', 'workflow_instance_id']);
        $instance = $instanceId === null ? null : $instances->find($instanceId, ['id', 'namespace']);
        if (($run instanceof WorkflowRun && $run->namespace !== $namespace)
            || ($instance instanceof WorkflowInstance && $instance->namespace !== $namespace)) {
            return [null, [
                'rule' => 'references.lineage_namespace_conflict',
                'message' => 'A lineage endpoint exists outside the target namespace.',
            ]];
        }
        if ($run instanceof WorkflowRun && $instanceId !== null
            && ($run->workflow_instance_id !== $instanceId || ! $instance instanceof WorkflowInstance)) {
            return [null, [
                'rule' => 'references.lineage_identity_conflict',
                'message' => 'A lineage run does not belong to the declared workflow instance.',
            ]];
        }

        return [$run, null];
    }

    /**
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $workflow
     * @return list<array{string, string|null}>
     */
    private static function references(array $bundle, array $workflow): array
    {
        $references = [];
        foreach ([
            'parents' => 'parent',
            'children' => 'child',
        ] as $section => $prefix) {
            foreach (self::rows($bundle['links'][$section] ?? null) as $link) {
                self::append(
                    $references,
                    $link[$prefix . '_workflow_run_id'] ?? null,
                    $link[$prefix . '_workflow_instance_id'] ?? null
                );
            }
        }
        foreach (self::rows($bundle['history_events'] ?? null) as $event) {
            $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
            if (($event['type'] ?? null) === 'WorkflowStarted') {
                self::append(
                    $references,
                    $payload['parent_workflow_run_id'] ?? null,
                    $payload['parent_workflow_instance_id'] ?? null
                );
                self::append($references, $payload['continued_from_run_id'] ?? null, $workflow['instance_id'] ?? null);
            } elseif (($event['type'] ?? null) === 'WorkflowContinuedAsNew') {
                self::append($references, $payload['continued_to_run_id'] ?? null, $workflow['instance_id'] ?? null);
            } elseif (in_array(
                $event['type'] ?? null,
                ['ChildWorkflowScheduled', 'ChildRunStarted', 'ChildRunCompleted',
                    'ChildRunFailed', 'ChildRunCancelled', 'ChildRunTerminated', 'ChildRunTimedOut'],
                true
            )) {
                self::append(
                    $references,
                    $payload['child_workflow_run_id'] ?? null,
                    $payload['child_workflow_instance_id'] ?? null
                );
            }
        }

        return $references;
    }

    /**
     * @param list<array{string, string|null}> $references
     */
    private static function append(array &$references, mixed $runId, mixed $instanceId): void
    {
        if (is_string($runId) && $runId !== '') {
            $references[] = [$runId, is_string($instanceId) && $instanceId !== '' ? $instanceId : null];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }
}
