<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
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
            if ($runId === ($workflow['run_id'] ?? null)) {
                $errors[] = [
                    'rule' => 'references.lineage_self_reference',
                    'message' => 'A workflow run cannot be its own parent, child or continuation.',
                ];
                continue;
            }
            [, $error] = self::endpoint($workflow['namespace'] ?? null, $runId, $instanceId, $lock);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    public static function existingEndpoint(
        ?string $namespace,
        string $runId,
        string $instanceId,
        string $originRunId
    ): ?WorkflowRun {
        if ($runId === $originRunId) {
            throw new InvalidArgumentException('A workflow run cannot be its own parent, child or continuation.');
        }
        [$run, $error] = self::endpoint($namespace, $runId, $instanceId, true);
        if ($error !== null) {
            throw new InvalidArgumentException($error['message']);
        }

        return $run;
    }

    /**
     * A repeat import cannot turn an omitted edge into authority over a later
     * unrelated endpoint. Recover missing edges only from persisted evidence.
     *
     * @param array<string, mixed> $bundle
     * @param array<string, mixed> $workflow
     * @return list<array{rule: string, message: string}>
     */
    public static function recoveryErrors(array $bundle, array $workflow, bool $lock = false): array
    {
        $runs = WorkflowRun::query();
        if ($lock) {
            $runs->lockForUpdate();
        }
        $origin = (clone $runs)->find($workflow['run_id'] ?? null);
        if (! $origin instanceof WorkflowRun
            || $origin->import_source !== EmbeddedV2ImportContract::IMPORT_SOURCE
            || $origin->import_dedupe_key !== ($bundle['dedupe_key'] ?? null)) {
            return [];
        }

        foreach ([
            'parents' => 'parent',
            'children' => 'child',
        ] as $section => $prefix) {
            $errors = self::relatedRecoveryErrors(
                self::rows($bundle['links'][$section] ?? null),
                $origin,
                $prefix,
                $lock
            );
            if ($errors !== []) {
                return $errors;
            }
        }

        return [];
    }

    /**
     * Older successor exports omit the predecessor's continuation sequence.
     * Recover it from the exact persisted event rather than changing an edge
     * according to which endpoint bundle was imported last.
     *
     * @param array<string, mixed> $link
     */
    public static function linkSequence(array $link, WorkflowRun $parent, string $childRunId): ?int
    {
        if (is_int($link['sequence'] ?? null)) {
            return $link['sequence'];
        }
        if (($link['type'] ?? null) !== 'continue_as_new' || ! is_string($link['id'] ?? null)) {
            return null;
        }
        $event = $parent->historyEvents()
            ->where('event_type', HistoryEventType::WorkflowContinuedAsNew->value)
            ->where('payload->continued_to_run_id', $childRunId)
            ->where('payload->workflow_link_id', $link['id'])
            ->lockForUpdate()
            ->first();

        return $event instanceof WorkflowHistoryEvent && is_int($event->payload['sequence'] ?? null)
            ? $event->payload['sequence'] : null;
    }

    /**
     * @param list<array<string, mixed>> $links
     * @return list<array{rule: string, message: string}>
     */
    private static function relatedRecoveryErrors(array $links, WorkflowRun $origin, string $prefix, bool $lock): array
    {
        foreach ($links as $link) {
            $relatedId = $link[$prefix . '_workflow_run_id'] ?? null;
            $runs = WorkflowRun::query();
            if ($lock) {
                $runs->lockForUpdate();
            }
            $related = is_string($relatedId) ? $runs->find($relatedId) : null;
            if (! $related instanceof WorkflowRun || WorkflowLink::query()->whereKey($link['id'] ?? null)->exists()) {
                continue;
            }
            $parent = $prefix === 'parent' ? $related : $origin;
            $child = $prefix === 'parent' ? $origin : $related;
            if (! self::storedRelationship($parent, $child, $link, $lock)) {
                return [[
                    'rule' => 'references.lineage_recovery_unverified',
                    'message' => 'A missing lineage edge cannot be restored without matching stored relationship evidence.',
                ]];
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $link
     */
    private static function storedRelationship(WorkflowRun $parent, WorkflowRun $child, array $link, bool $lock): bool
    {
        if ($parent->namespace !== $child->namespace || $parent->id === $child->id) {
            return false;
        }
        $started = $child->historyEvents()
            ->where('event_type', HistoryEventType::WorkflowStarted->value);
        if ($lock) {
            $started->lockForUpdate();
        }
        foreach ($started->get() as $event) {
            if ($event instanceof WorkflowHistoryEvent
                && self::startedRelationship($parent, $child, $link, $event->payload, $lock)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $link
     * @param array<string, mixed> $started
     */
    private static function startedRelationship(
        WorkflowRun $parent,
        WorkflowRun $child,
        array $link,
        array $started,
        bool $lock,
    ): bool {
        if (($link['type'] ?? null) === 'child_workflow') {
            return self::storedChildRelationship($parent, $child, $link, $started, $lock);
        }
        if ($parent->workflow_instance_id !== $child->workflow_instance_id
            || ($started['continued_from_run_id'] ?? null) !== $parent->id
            || ! is_string($link['id'] ?? null)
            || ($started['workflow_link_id'] ?? null) !== $link['id']) {
            return false;
        }

        return match ($link['type'] ?? null) {
            'continue_as_new' => self::storedContinuation($parent, $child, $link, $lock),
            'redrive' => self::storedRedrive($parent, $child, $link, $started, $lock),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $link
     * @param array<string, mixed> $started
     */
    private static function storedChildRelationship(
        WorkflowRun $parent,
        WorkflowRun $child,
        array $link,
        array $started,
        bool $lock,
    ): bool {
        if (($started['parent_workflow_run_id'] ?? null) !== $parent->id
            || ($started['parent_workflow_instance_id'] ?? null) !== $parent->workflow_instance_id
            || ($started['parent_sequence'] ?? null) !== ($link['sequence'] ?? null)) {
            return false;
        }
        $history = $parent->historyEvents()
            ->whereIn('event_type', [
                HistoryEventType::ChildWorkflowScheduled->value, HistoryEventType::ChildRunStarted->value,
            ]);
        if ($lock) {
            $history->lockForUpdate();
        }
        foreach ($history->get() as $event) {
            if ($event instanceof WorkflowHistoryEvent && self::childCallMatches(
                $event->payload,
                $child,
                $link,
                $started
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $call
     * @param array<string, mixed> $link
     * @param array<string, mixed> $started
     */
    private static function childCallMatches(array $call, WorkflowRun $child, array $link, array $started): bool
    {
        $callId = $call['child_call_id'] ?? $call['workflow_link_id'] ?? null;

        return ($call['child_workflow_run_id'] ?? null) === $child->id
            && ($call['child_workflow_instance_id'] ?? null) === $child->workflow_instance_id
            && ($call['sequence'] ?? null) === ($link['sequence'] ?? null)
            && is_string($callId) && $callId !== ''
            && (($link['id'] ?? null) === $callId || ($link['id'] ?? null) === ($call['workflow_link_id'] ?? null))
            && (! isset($started['child_call_id']) || $started['child_call_id'] === $callId);
    }

    /**
     * @param array<string, mixed> $link
     */
    private static function storedContinuation(WorkflowRun $parent, WorkflowRun $child, array $link, bool $lock): bool
    {
        $history = $parent->historyEvents()
            ->where('event_type', HistoryEventType::WorkflowContinuedAsNew->value)
            ->where('payload->continued_to_run_id', $child->id)
            ->where('payload->workflow_link_id', $link['id']);
        if (isset($link['sequence'])) {
            $history->where('payload->sequence', $link['sequence']);
        }
        if ($lock) {
            $history->lockForUpdate();
        }

        return $history->first() instanceof WorkflowHistoryEvent;
    }

    /**
     * @param array<string, mixed> $link
     * @param array<string, mixed> $started
     */
    private static function storedRedrive(
        WorkflowRun $parent,
        WorkflowRun $child,
        array $link,
        array $started,
        bool $lock,
    ): bool {
        if (($started['recovery_kind'] ?? null) !== 'redrive'
            || ($started['resume_step_sequence'] ?? null) !== ($link['sequence'] ?? null)) {
            return false;
        }
        $commands = WorkflowCommand::query()->whereKey($started['workflow_command_id'] ?? null)
            ->where('workflow_instance_id', $child->workflow_instance_id)
            ->where('workflow_run_id', $child->id)
            ->where('command_type', CommandType::Redrive->value)
            ->where('requested_workflow_run_id', $parent->id)
            ->where('resolved_workflow_run_id', $child->id);
        if ($lock) {
            $commands->lockForUpdate();
        }

        return $commands->first() instanceof WorkflowCommand;
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
