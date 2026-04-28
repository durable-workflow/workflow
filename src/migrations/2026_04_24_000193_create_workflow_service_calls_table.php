<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    private const TABLE = 'workflow_service_calls';

    private const ENDPOINT_INDEX = 'wf_service_calls_endpoint_idx';

    private const SERVICE_INDEX = 'wf_service_calls_service_idx';

    private const OPERATION_INDEX = 'wf_service_calls_operation_idx';

    private const NAMESPACE_INDEX = 'wf_service_calls_namespace_idx';

    private const CALLER_NAMESPACE_INDEX = 'wf_service_calls_caller_namespace_idx';

    private const TARGET_NAMESPACE_INDEX = 'wf_service_calls_target_namespace_idx';

    private const CALLER_INSTANCE_INDEX = 'wf_service_calls_caller_instance_idx';

    private const CALLER_RUN_INDEX = 'wf_service_calls_caller_run_idx';

    private const LINKED_INSTANCE_INDEX = 'wf_service_calls_linked_instance_idx';

    private const LINKED_RUN_INDEX = 'wf_service_calls_linked_run_idx';

    private const LINKED_UPDATE_INDEX = 'wf_service_calls_linked_update_idx';

    private const STATUS_INDEX = 'wf_service_calls_status_idx';

    private const MODE_INDEX = 'wf_service_calls_mode_idx';

    private const BINDING_KIND_INDEX = 'wf_service_calls_binding_kind_idx';

    private const IDEMPOTENCY_INDEX = 'wf_service_calls_idempotency_idx';

    private const NAMESPACE_STATUS_INDEX = 'wf_service_calls_namespace_status_idx';

    private const TARGET_STATUS_INDEX = 'wf_service_calls_target_status_idx';

    private const ACCEPTED_AT_INDEX = 'wf_service_calls_accepted_at_idx';

    public function up(): void
    {
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_service_endpoint_id', 26)
                ->index(self::ENDPOINT_INDEX);
            $table->string('workflow_service_id', 26)
                ->index(self::SERVICE_INDEX);
            $table->string('workflow_service_operation_id', 26)
                ->index(self::OPERATION_INDEX);
            $table->string('namespace', 255)
                ->nullable()
                ->index(self::NAMESPACE_INDEX);
            $table->string('endpoint_name', 191);
            $table->string('service_name', 191);
            $table->string('operation_name', 191);
            $table->string('caller_namespace', 255)
                ->nullable()
                ->index(self::CALLER_NAMESPACE_INDEX);
            $table->string('caller_workflow_instance_id', 191)
                ->nullable()
                ->index(self::CALLER_INSTANCE_INDEX);
            $table->string('caller_workflow_run_id', 26)
                ->nullable()
                ->index(self::CALLER_RUN_INDEX);
            $table->string('target_namespace', 255)
                ->nullable()
                ->index(self::TARGET_NAMESPACE_INDEX);
            $table->string('linked_workflow_instance_id', 191)
                ->nullable()
                ->index(self::LINKED_INSTANCE_INDEX);
            $table->string('linked_workflow_run_id', 26)
                ->nullable()
                ->index(self::LINKED_RUN_INDEX);
            $table->string('linked_workflow_update_id', 26)
                ->nullable()
                ->index(self::LINKED_UPDATE_INDEX);
            $table->string('status', 32)
                ->index(self::STATUS_INDEX);
            $table->string('operation_mode', 32)
                ->index(self::MODE_INDEX);
            $table->string('resolved_binding_kind', 64)
                ->index(self::BINDING_KIND_INDEX);
            $table->string('resolved_target_reference', 191)
                ->nullable();
            $table->string('payload_codec')
                ->nullable();
            $table->string('input_payload_reference', 191)
                ->nullable();
            $table->string('output_payload_reference', 191)
                ->nullable();
            $table->string('failure_payload_reference', 191)
                ->nullable();
            $table->text('failure_message')
                ->nullable();
            $table->string('idempotency_key', 191)
                ->nullable()
                ->index(self::IDEMPOTENCY_INDEX);
            $table->json('deadline_policy')
                ->nullable();
            $table->json('idempotency_policy')
                ->nullable();
            $table->json('cancellation_policy')
                ->nullable();
            $table->json('retry_policy')
                ->nullable();
            $table->json('boundary_policy')
                ->nullable();
            $table->json('metadata')
                ->nullable();
            $table->timestamp('accepted_at', 6)
                ->nullable()
                ->index(self::ACCEPTED_AT_INDEX);
            $table->timestamp('started_at', 6)
                ->nullable();
            $table->timestamp('completed_at', 6)
                ->nullable();
            $table->timestamp('failed_at', 6)
                ->nullable();
            $table->timestamp('cancelled_at', 6)
                ->nullable();
            $table->timestamps(6);

            $table->index(['namespace', 'status'], self::NAMESPACE_STATUS_INDEX);
            $table->index(['target_namespace', 'status'], self::TARGET_STATUS_INDEX);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
