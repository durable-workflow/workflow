<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    private const TABLE = 'workflow_schedule_history_events';

    private const WORKFLOW_SCHEDULE_INDEX = 'wf_schedule_history_workflow_schedule_idx';

    private const SCHEDULE_INDEX = 'wf_schedule_history_schedule_idx';

    private const NAMESPACE_INDEX = 'wf_schedule_history_namespace_idx';

    private const WORKFLOW_INSTANCE_INDEX = 'wf_schedule_history_instance_idx';

    private const WORKFLOW_RUN_INDEX = 'wf_schedule_history_run_idx';

    private const SCHEDULE_SEQUENCE_UNIQUE = 'wf_schedule_history_schedule_sequence_unique';

    private const NAMESPACE_SCHEDULE_INDEX = 'wf_schedule_history_namespace_schedule_idx';

    private const EVENT_RECORDED_INDEX = 'wf_schedule_history_event_recorded_idx';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_schedule_id', 26)
                ->index(self::WORKFLOW_SCHEDULE_INDEX);
            $table->string('schedule_id', 255)
                ->index(self::SCHEDULE_INDEX);
            $table->string('namespace', 255)
                ->nullable()
                ->index(self::NAMESPACE_INDEX);
            $table->unsignedInteger('sequence');
            $table->string('event_type');
            $table->json('payload')
                ->nullable();
            $table->string('workflow_instance_id', 191)
                ->nullable()
                ->index(self::WORKFLOW_INSTANCE_INDEX);
            $table->string('workflow_run_id', 26)
                ->nullable()
                ->index(self::WORKFLOW_RUN_INDEX);
            $table->timestamp('recorded_at', 6)
                ->nullable();
            $table->timestamps(6);

            $table->unique(['workflow_schedule_id', 'sequence'], self::SCHEDULE_SEQUENCE_UNIQUE);
            $table->index(['namespace', 'schedule_id'], self::NAMESPACE_SCHEDULE_INDEX);
            $table->index(['event_type', 'recorded_at'], self::EVENT_RECORDED_INDEX);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
