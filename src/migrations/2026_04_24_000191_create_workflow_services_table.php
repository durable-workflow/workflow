<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    private const TABLE = 'workflow_services';

    private const ENDPOINT_INDEX = 'wf_services_endpoint_idx';

    private const NAMESPACE_INDEX = 'wf_services_namespace_idx';

    private const NAME_INDEX = 'wf_services_name_idx';

    private const NAMESPACE_NAME_INDEX = 'wf_services_namespace_name_idx';

    private const NAMESPACE_ENDPOINT_NAME_UNIQUE = 'wf_services_namespace_endpoint_name_unique';

    public function up(): void
    {
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('workflow_service_endpoint_id', 26)
                ->index(self::ENDPOINT_INDEX);
            $table->string('namespace', 255)
                ->nullable()
                ->index(self::NAMESPACE_INDEX);
            $table->string('service_name', 191)
                ->index(self::NAME_INDEX);
            $table->text('description')
                ->nullable();
            $table->json('boundary_policy')
                ->nullable();
            $table->json('metadata')
                ->nullable();
            $table->timestamps(6);

            $table->index(['namespace', 'service_name'], self::NAMESPACE_NAME_INDEX);
            $table->unique(
                ['namespace', 'workflow_service_endpoint_id', 'service_name'],
                self::NAMESPACE_ENDPOINT_NAME_UNIQUE,
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
