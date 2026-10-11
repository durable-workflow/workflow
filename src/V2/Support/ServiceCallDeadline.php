<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Carbon\CarbonInterface;
use Workflow\V2\Enums\ServiceCallOutcome;
use Workflow\V2\Enums\ServiceCallStatus;
use Workflow\V2\Models\WorkflowServiceCall;

final class ServiceCallDeadline
{
    public static function expire(WorkflowServiceCall $call): bool
    {
        if ($call->accepted_at === null || $call->call_deadline_at === null
            || ! in_array($call->status, ['pending', 'accepted', 'started'], true)) {
            return false;
        }
        $expired = $call->getConnection()
            ->transaction(static function () use ($call): bool {
                /** @var WorkflowServiceCall $locked */
                $locked = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
                    ->lockForUpdate()
                    ->findOrFail($call->id);

                return self::expireLocked($locked, now());
            });
        $call->refresh();

        return $expired;
    }

    /**
     * @return list<string>
     */
    public static function expiredCallIds(
        int $limit,
        array $runIds = [],
        ?string $instanceId = null,
        ?string $connection = null,
        ?string $queue = null,
    ): array {
        $query = ConfiguredV2Models::query('service_call_model', WorkflowServiceCall::class)
            ->whereIn('status', ['pending', 'accepted', 'started'])
            ->whereNotNull('accepted_at')
            ->whereNotNull('call_deadline_at')
            ->where('call_deadline_at', '<=', now()->utc()->format('Y-m-d H:i:s.u'));
        if ($runIds !== []) {
            $query->where(static function ($scope) use ($runIds): void {
                $scope->whereIn('caller_workflow_run_id', $runIds)
                    ->orWhereIn('linked_workflow_run_id', $runIds);
            });
        }
        if ($instanceId !== null) {
            $query->where(static function ($scope) use ($instanceId): void {
                $scope->where('caller_workflow_instance_id', $instanceId)
                    ->orWhere('linked_workflow_instance_id', $instanceId);
            });
        }
        if ($connection !== null || $queue !== null) {
            $scopeRun = static function ($run) use ($connection, $queue): void {
                if ($connection !== null) {
                    $run->where('connection', $connection);
                }
                if ($queue !== null) {
                    $run->where('queue', $queue);
                }
            };
            $query->where(static function ($scope) use ($scopeRun): void {
                $scope->whereHas('callerRun', $scopeRun)
                    ->orWhereHas('linkedRun', $scopeRun)
                    ->orWhere(static function ($unlinked): void {
                        // Calls without a run have no queue owner. Any matching
                        // loop may enforce them under the same call row fence.
                        $unlinked->whereNull('caller_workflow_run_id')
                            ->whereNull('linked_workflow_run_id');
                    });
            });
        }

        return $query->orderBy('call_deadline_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->all();
    }

    /**
     * Called only under the call's terminal transition row lock.
     */
    public static function expireLocked(WorkflowServiceCall $call, CarbonInterface $instant): bool
    {
        if (! in_array($call->status, ['pending', 'accepted', 'started'], true)
            || $call->accepted_at === null || $call->call_deadline_at === null
            || $call->call_deadline_at->gt($instant)) {
            return false;
        }

        $message = 'Service call deadline expired.';
        $metadata = is_array($call->metadata) ? $call->metadata : [];
        unset($metadata['nexus_result']);
        $call->forceFill([
            'status' => ServiceCallStatus::Failed->value,
            'outcome' => ServiceCallOutcome::TimedOut->value,
            'outcome_category' => ServiceCallOutcome::TimedOut->category(),
            'outcome_reason' => ServiceCallOutcome::TimedOut->value,
            'outcome_message' => $message,
            'failure_message' => $message,
            'outcome_metadata' => [
                'service_error_type' => 'ServiceCallTimedOut',
                'caller_observed_error_type' => 'ServiceCallTimedOut',
                'typed_error_message' => $message,
                'call_deadline_at' => $call->call_deadline_at->copy()
                    ->utc()
                    ->format('Y-m-d\TH:i:s.u\Z'),
            ],
            'failed_at' => $instant,
            'completed_at' => null,
            'output_payload_reference' => null,
            'failure_payload_reference' => null,
            'metadata' => $metadata,
        ])->save();
        ServiceCallWorkflowDelivery::enqueue($call);

        return true;
    }
}
