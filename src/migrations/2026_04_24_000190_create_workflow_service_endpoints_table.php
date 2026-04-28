<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    private const TABLE = 'workflow_service_endpoints';

    private const NAMESPACE_INDEX = 'wf_service_endpoints_namespace_idx';

    private const NAME_INDEX = 'wf_service_endpoints_name_idx';

    private const NAMESPACE_NAME_UNIQUE = 'wf_service_endpoints_namespace_name_unique';

    public function up(): void
    {
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->string('id', 26)
                ->primary();
            $table->string('namespace', 255)
                ->nullable()
                ->index(self::NAMESPACE_INDEX);
            $table->string('endpoint_name', 191)
                ->index(self::NAME_INDEX);
            $table->text('description')
                ->nullable();
            $table->json('metadata')
                ->nullable();
            $table->timestamps(6);

            $table->unique(['namespace', 'endpoint_name'], self::NAMESPACE_NAME_UNIQUE);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
