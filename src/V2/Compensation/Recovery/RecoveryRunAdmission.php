<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Recovery;

use LogicException;
use Workflow\V2\CommandContext;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ConfiguredV2Models;
use Workflow\V2\Support\TaskSchedulingFields;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkerCompatibility;
use Workflow\V2\Support\WorkflowDefinition;

/**
 * Data-only native admission after the scope service authorizes the request.
 * Initial recovery has its own policy profile, separate from the forward run.
 * Restart inherits the recovery's absolute deadlines; it grants no new allowance.
 *
 * @internal Experimental retained compensation admission, not a general reset API.
 */
final readonly class RecoveryRunAdmission
{
    public StartOptions $profile;

    public function __construct(
        public ScopeIdentity $scope,
        public string $originalRunId,
        public string $requestKey,
        public ?string $expectedPredecessorRunId,
        ?StartOptions $profile = null,
    ) {
        PortableDescriptor::identifier($originalRunId, 'original run');
        PortableDescriptor::identifier($requestKey, 'recovery request');
        if ($expectedPredecessorRunId !== null) {
            PortableDescriptor::identifier($expectedPredecessorRunId, 'recovery predecessor');
        }
        $this->profile = $profile ?? StartOptions::returnExistingActive();
    }

    public function token(): string
    {
        // The predecessor is receipt content, never part of request identity.
        return hash('sha256', PortableDescriptor::encode([
            'compensation_recovery_v1', $this->scope->application, $this->scope->namespace,
            $this->scope->recoveryInstanceId(), $this->requestKey,
        ]));
    }

    public function intent(
        WorkflowInstance $instance,
        string $workflowClass,
        ?string $connection,
        ?string $queue
    ): array {
        if ($instance->id !== $this->scope->recoveryInstanceId()
            || $instance->namespace !== $this->scope->namespace
            || $workflowClass !== RecoveryWorkflow::class
            || $instance->workflow_type !== TypeRegistry::for(RecoveryWorkflow::class)) {
            throw new LogicException('Recovery admission requires the exact canonical scope and registered type.');
        }
        $origin = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($this->originalRunId);
        if (! $origin instanceof WorkflowRun || $origin->namespace !== $instance->namespace || ! $origin->status->isTerminal()) {
            throw new LogicException('Recovery admission requires a terminal original run in the exact namespace.');
        }
        $definition = WorkflowDefinition::fingerprint($workflowClass);
        if ($definition === null) {
            throw new LogicException('Recovery admission requires a registered definition fingerprint.');
        }
        $profile = [
            'connection' => $connection,
            'queue' => $queue,
            'compatibility' => WorkerCompatibility::current(),
            'business_key' => $this->profile->businessKey,
            'labels' => $this->profile->labels,
            'memo' => $this->profile->memo,
            'search_attributes' => $this->profile->searchAttributes,
            'execution_timeout_seconds' => $this->profile->executionTimeoutSeconds,
            'run_timeout_seconds' => $this->profile->runTimeoutSeconds,
            'timeout_cleanup_seconds' => $this->profile->timeoutCleanupSeconds,
            'priority' => $this->profile->priority,
            'fairness_key' => $this->profile->fairnessKey,
            'fairness_weight' => $this->profile->fairnessWeight,
        ];
        $binding = [
            'scope' => $this->scope->toArray(),
            'original_run_id' => $this->originalRunId,
            'workflow_class' => $workflowClass,
            'workflow_type' => $instance->workflow_type,
            'definition' => $definition,
            'arguments' => [$this->scope->toArray()],
            'profile' => $profile,
        ];
        return [
            'version' => 1,
            'binding' => $binding,
            'binding_fingerprint' => hash('sha256', PortableDescriptor::encode($binding)),
            'expected_predecessor_run_id' => $this->expectedPredecessorRunId,
        ];
    }

    public function retained(WorkflowInstance $instance, array $intent): ?WorkflowCommand
    {
        $command = ConfiguredV2Models::query('command_model', WorkflowCommand::class)
            ->where('workflow_instance_id', $instance->id)
            ->where('request_id', $this->token())
            ->lockForUpdate()
            ->first();
        if (! $command instanceof WorkflowCommand) {
            return null;
        }
        $receipt = $command->context['compensation_recovery'] ?? null;
        if ($command->command_type !== CommandType::Start || $command->status !== CommandStatus::Accepted
            || ! is_array($receipt) || ! is_array($receipt['intent'] ?? null)
            || PortableDescriptor::encode($receipt['intent']) !== PortableDescriptor::encode($intent)) {
            throw new LogicException('Recovery request identity was reused with different admission content.');
        }
        $run = ConfiguredV2Models::query('run_model', WorkflowRun::class)->find($command->workflow_run_id);
        self::assertRun($instance, $run, $receipt);
        if (self::sourceReceipt($instance, $run) === null) {
            throw new LogicException('Recovery receipt has no matching native creation history.');
        }
        return $command;
    }

    /**
     * Return the creation receipt only when the exact current run can be adopted
     * or succeeded. No native rows are changed by a refusal.
     */
    public function predecessor(WorkflowInstance $instance, ?WorkflowRun $run, array $intent): ?array
    {
        if ($run === null) {
            if ($this->expectedPredecessorRunId !== null) {
                throw new LogicException('Recovery predecessor is no longer current.');
            }
            return null;
        }
        $receipt = self::sourceReceipt($instance, $run);
        if (! is_array($receipt)
            || ($receipt['intent']['binding_fingerprint'] ?? null) !== $intent['binding_fingerprint']) {
            throw new LogicException('Recovery run has no matching native admission lineage.');
        }
        self::assertRun($instance, $run, $receipt);
        if (! $run->status->isTerminal()) {
            if (PortableDescriptor::encode($receipt['intent']) !== PortableDescriptor::encode($intent)) {
                throw new LogicException('Active recovery is not the successor of the requested predecessor.');
            }
            return $receipt;
        }
        if ($run->id !== $this->expectedPredecessorRunId
            || ! in_array($run->status, [RunStatus::Cancelled, RunStatus::Terminated], true)) {
            throw new LogicException('Only the exact current cancelled or terminated recovery may restart.');
        }
        foreach ([$run->execution_deadline_at, $run->run_deadline_at] as $deadline) {
            if ($deadline !== null && $deadline->lessThanOrEqualTo(now())) {
                throw new LogicException('Recovery restart cannot renew an exhausted deadline.');
            }
        }
        return $receipt;
    }

    public function context(
        CommandContext $context,
        array $intent,
        ?WorkflowRun $predecessor,
        ?array $creationReceipt = null
    ): CommandContext {
        return $context->with([
            'compensation_recovery' => [
                'version' => 1,
                'operation' => 'start',
                'lineage_operation' => $creationReceipt['lineage_operation'] ?? 'start',
                'request_key' => $this->requestKey,
                'intent' => $intent,
                'execution_deadline_at' => $predecessor?->execution_deadline_at?->format('Y-m-d H:i:s.u'),
                'run_deadline_at' => $predecessor?->run_deadline_at?->format('Y-m-d H:i:s.u'),
            ],
        ]);
    }

    public function bind(WorkflowCommand $command, WorkflowRun $run): void
    {
        $context = $command->context;
        $context['compensation_recovery']['execution_deadline_at'] = $run->execution_deadline_at?->format(
            'Y-m-d H:i:s.u'
        );
        $context['compensation_recovery']['run_deadline_at'] = $run->run_deadline_at?->format('Y-m-d H:i:s.u');
        $context['compensation_recovery']['timeout_cleanup_work_deadline_at'] = $run->timeout_cleanup_work_deadline_at?->format(
            'Y-m-d H:i:s.u'
        );
        $command->forceFill([
            'request_id' => $this->token(),
            'context' => $context,
        ])->save();
    }

    public function link(WorkflowRun $predecessor, WorkflowRun $successor): WorkflowLink
    {
        return ConfiguredV2Models::query('link_model', WorkflowLink::class)->create([
            'link_type' => 'compensation_recovery_restart',
            'parent_workflow_run_id' => $predecessor->id,
            'parent_workflow_instance_id' => $predecessor->workflow_instance_id,
            'child_workflow_run_id' => $successor->id,
            'child_workflow_instance_id' => $successor->workflow_instance_id,
        ]);
    }

    /**
     * Carry the binding through native failed-run redrive. Its recorded technical
     * allowance is not renewed by a later cancelled/terminated restart.
     */
    public static function carryToRedrive(
        WorkflowInstance $instance,
        WorkflowRun $source,
        WorkflowRun $successor,
        WorkflowCommand $command,
        ?array $receipt,
    ): ?array {
        if ($receipt === null) {
            return null;
        }
        self::assertRun($instance, $source, $receipt);
        $receipt['operation'] = 'redrive';
        $receipt['lineage_operation'] = 'redrive';
        $receipt['request_key'] = $command->request_id ?? $command->id;
        $receipt['intent']['expected_predecessor_run_id'] = $source->id;
        $receipt['execution_deadline_at'] = $successor->execution_deadline_at?->format('Y-m-d H:i:s.u');
        $receipt['run_deadline_at'] = $successor->run_deadline_at?->format('Y-m-d H:i:s.u');
        $receipt['timeout_cleanup_work_deadline_at'] = $successor->timeout_cleanup_work_deadline_at?->format(
            'Y-m-d H:i:s.u'
        );
        $context = $command->context;
        $context['compensation_recovery'] = $receipt;
        $command->forceFill([
            'context' => $context,
        ])->save();
        return $receipt;
    }

    /**
     * Either atomic copy identifies a recovery. Missing, malformed or divergent
     * copies must never turn it into an ordinary unbound start/redrive.
     */
    public static function sourceReceipt(WorkflowInstance $instance, WorkflowRun $run): ?array
    {
        $creation = ConfiguredV2Models::query('command_model', WorkflowCommand::class)
            ->where('workflow_run_id', $run->id)
            ->where('status', CommandStatus::Accepted->value)
            ->whereIn('command_type', [CommandType::Start->value, CommandType::Redrive->value])
            ->whereIn('outcome', [CommandOutcome::StartedNew->value, CommandOutcome::Redriven->value])
            ->first();
        $started = $run->relationLoaded('historyEvents')
            ? $run->historyEvents->firstWhere('event_type', HistoryEventType::WorkflowStarted)
            : ConfiguredV2Models::query('history_event_model', WorkflowHistoryEvent::class)
                ->where('workflow_run_id', $run->id)
                ->where('event_type', HistoryEventType::WorkflowStarted->value)
                ->orderBy('sequence')
                ->first();
        $payload = is_array($started?->payload) ? $started->payload : [];
        $context = is_array($creation?->context) ? $creation->context : [];
        $commandHasReceipt = array_key_exists('compensation_recovery', $context);
        $historyHasReceipt = array_key_exists('compensation_recovery', $payload);
        if (! $commandHasReceipt && ! $historyHasReceipt) {
            return null;
        }
        $receipt = $context['compensation_recovery'] ?? null;
        $marker = $payload['compensation_recovery'] ?? null;
        if (! $commandHasReceipt || ! $historyHasReceipt || ! is_array($receipt) || ! is_array($marker)
            || ($payload['workflow_command_id'] ?? null) !== $creation?->id
            || PortableDescriptor::encode($receipt) !== PortableDescriptor::encode($marker)
            || ($receipt['intent']['binding']['definition'] ?? null) !== ($payload['workflow_definition_fingerprint'] ?? null)) {
            throw new LogicException('Recovery redrive requires matching native command and history receipts.');
        }
        self::assertRun($instance, $run, $receipt);
        return $receipt;
    }

    private static function assertRun(WorkflowInstance $instance, mixed $run, array $receipt): void
    {
        $binding = $receipt['intent']['binding'] ?? null;
        $fingerprint = $receipt['intent']['binding_fingerprint'] ?? null;
        if (! is_array($binding) || ! is_string($fingerprint)
            || ($receipt['version'] ?? null) !== 1 || ($receipt['intent']['version'] ?? null) !== 1
            || ! in_array($receipt['lineage_operation'] ?? null, ['start', 'redrive'], true)
            || ! hash_equals(hash('sha256', PortableDescriptor::encode($binding)), $fingerprint)) {
            throw new LogicException('Recovery receipt binding is invalid or has an unsupported version.');
        }
        $profile = $binding['profile'] ?? [];
        if (! $run instanceof WorkflowRun || ! is_array($binding)
            || $run->workflow_instance_id !== $instance->id || $run->namespace !== $instance->namespace
            || $run->workflow_class !== ($binding['workflow_class'] ?? null)
            || $run->workflow_type !== ($binding['workflow_type'] ?? null)
            || PortableDescriptor::encode($run->workflowArguments()) !== PortableDescriptor::encode(
                $binding['arguments'] ?? []
            )
            || $run->connection !== ($profile['connection'] ?? null) || $run->queue !== ($profile['queue'] ?? null)
            || $run->compatibility !== ($profile['compatibility'] ?? null)
            || $run->run_timeout_seconds !== ($profile['run_timeout_seconds'] ?? null)
            || TaskSchedulingFields::forRun($run) !== [
                'priority' => $profile['priority'] ?? null,
                'fairness_key' => $profile['fairness_key'] ?? null,
                'fairness_weight' => $profile['fairness_weight'] ?? null,
            ]
            || $run->execution_deadline_at?->format('Y-m-d H:i:s.u') !== ($receipt['execution_deadline_at'] ?? null)
            || $run->run_deadline_at?->format('Y-m-d H:i:s.u') !== ($receipt['run_deadline_at'] ?? null)
            || $run->timeout_cleanup_work_deadline_at?->format(
                'Y-m-d H:i:s.u'
            ) !== ($receipt['timeout_cleanup_work_deadline_at'] ?? null)) {
            throw new LogicException('Recovery receipt no longer matches its native run.');
        }
        $predecessor = $receipt['intent']['expected_predecessor_run_id'] ?? null;
        if ($predecessor !== null && ! ConfiguredV2Models::query('link_model', WorkflowLink::class)
            ->where(
                'link_type',
                ($receipt['lineage_operation'] ?? null) === 'redrive' ? 'redrive' : 'compensation_recovery_restart'
            )
            ->where('parent_workflow_run_id', $predecessor)
            ->where('child_workflow_run_id', $run->id)
            ->where('parent_workflow_instance_id', $instance->id)
            ->where('child_workflow_instance_id', $instance->id)
            ->exists()) {
            throw new LogicException('Recovery successor is missing its predecessor lineage.');
        }
    }
}
