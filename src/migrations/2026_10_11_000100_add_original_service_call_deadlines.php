<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Support\ServiceCallDeadlinePolicy;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        // MySQL DDL commits separately. An interrupted backfill can safely retry.
        if (! Schema::hasColumn('workflow_service_calls', 'call_deadline_at')) {
            Schema::table('workflow_service_calls', static function (Blueprint $table): void {
                $table->dateTime('call_deadline_at', 6)
                    ->nullable();
                $table->index(['status', 'call_deadline_at'], 'wf_service_calls_deadline_idx');
            });
        }
        $connection = DB::connection($this->getConnection());
        $timezone = date_default_timezone_get();
        $connection->transaction(static function () use ($connection, $timezone): void {
            $connection->table('workflow_service_calls')
                ->whereNotNull('accepted_at')
                ->whereNotNull('deadline_policy')
                ->whereNull('call_deadline_at')
                ->orderBy('id')
                ->chunkById(100, static function ($calls) use ($connection, $timezone): void {
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
                            ->update([
                                'call_deadline_at' => $deadline?->format('Y-m-d H:i:s.u'),
                            ]);
                    }
                });
        });
    }

    public function down(): void
    {
        Schema::table('workflow_service_calls', static function (Blueprint $table): void {
            $table->dropIndex('wf_service_calls_deadline_idx');
            $table->dropColumn('call_deadline_at');
        });
    }
};
