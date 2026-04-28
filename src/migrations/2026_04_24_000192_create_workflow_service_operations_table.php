<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    private const TABLE = 'workflow_service_operations';

    private const ENDPOINT_INDEX = 'wf_service_ops_endpoint_idx';

    private const SERVICE_INDEX = 'wf_service_ops_service_idx';

    private const NAMESPACE_INDEX = 'wf_service_ops_namespace_idx';

    private const NAME_INDEX = 'wf_service_ops_name_idx';

    private const MODE_INDEX = 'wf_service_ops_mode_idx';

    private const BINDING_KIND_INDEX = 'wf_service_ops_binding_kind_idx';

    private const NAMESPACE_NAME_INDEX = 'wf_service_ops_namespace_name_idx';

    private const NAMESPACE_SERVICE_NAME_UNIQUE = 'wf_service_ops_namespace_service_name_unique';

    public function up(): void
    {
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_service_endpoint_id', 26)
                ->index(self::ENDPOINT_INDEX);
            $table->string('workflow_service_id', 26)
                ->index(self::SERVICE_INDEX);
            $table->string('namespace', 255)
                ->nullable()
                ->index(self::NAMESPACE_INDEX);
            $table->string('operation_name', 191)
                ->index(self::NAME_INDEX);
            $table->text('description')
                ->nullable();
            $table->string('operation_mode', 32)
                ->index(self::MODE_INDEX);
            $table->string('handler_binding_kind', 64)
                ->index(self::BINDING_KIND_INDEX);
            $table->string('handler_target_reference', 191)
                ->nullable();
            $table->json('handler_binding')
                ->nullable();
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
            $table->timestamps(6);

            $table->index(['namespace', 'operation_name'], self::NAMESPACE_NAME_INDEX);
            $table->unique(
                ['namespace', 'workflow_service_id', 'operation_name'],
                self::NAMESPACE_SERVICE_NAME_UNIQUE,
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
