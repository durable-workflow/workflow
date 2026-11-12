<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Support\ServiceCallDeadlinePolicy;

return new class() extends WorkflowMigration {
    /**
     * The column is added and committed first. Calls are then filled in
     * batches that each commit on their own, and the index is built last
     * without blocking writes. A re-run only picks up calls that still have
     * no deadline, so an interrupted run can simply be run again.
     */
    public $withinTransaction = false;

    public int $backfillBatchSize = 100;

    public function up(): void
    {
        if (! Schema::hasColumn('workflow_service_calls', 'call_deadline_at')) {
            $this->withSchemaLockBudget(static function (): void {
                Schema::table('workflow_service_calls', static function (Blueprint $table): void {
                    $table->dateTime('call_deadline_at', 6)
                        ->nullable();
                });
            });
        }
        $connection = DB::connection($this->getConnection());
        $timezone = date_default_timezone_get();
        $connection->table('workflow_service_calls')
            ->whereNotNull('accepted_at')
            ->whereNotNull('deadline_policy')
            ->whereNull('call_deadline_at')
            ->orderBy('id')
            ->chunkById($this->backfillBatchSize, static fn ($calls) => $connection->transaction(static function () use (
                $connection,
                $calls,
                $timezone
            ): void {
                foreach ($calls as $call) {
                    try {
                        $policy = json_decode($call->deadline_policy, true, flags: JSON_THROW_ON_ERROR);
                        $deadline = ServiceCallDeadlinePolicy::resolve(
                            $policy,
                            CarbonImmutable::parse($call->accepted_at, $timezone)
                        );
                    } catch (\Throwable $failure) {
                        throw new RuntimeException(
                            'Cannot resolve original deadline for service call ' . $call->id . '.',
                            0,
                            $failure
                        );
                    }
                    $connection->table('workflow_service_calls')
                        ->where('id', $call->id)
                        ->whereNull('call_deadline_at')
                        ->update([
                            'call_deadline_at' => $deadline?->format('Y-m-d H:i:s.u'),
                        ]);
                }
            }));
        $this->createIndexOnline('workflow_service_calls', 'wf_service_calls_deadline_idx', [
            'status',
            'call_deadline_at',
        ]);
    }

    public function down(): void
    {
        Schema::table('workflow_service_calls', static function (Blueprint $table): void {
            $table->dropIndex('wf_service_calls_deadline_idx');
            $table->dropColumn('call_deadline_at');
        });
    }
};
