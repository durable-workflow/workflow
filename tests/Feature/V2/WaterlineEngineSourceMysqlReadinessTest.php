<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\WaterlineEngineSource;

final class WaterlineEngineSourceMysqlReadinessTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('readiness_probe_run_summaries');

        parent::tearDown();
    }

    public function testMysqlInspectionUsesOneFreshListingAndDetectsTableChanges(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql'
            || ! method_exists($connection->getSchemaBuilder(), 'parseSchemaAndTable')) {
            $this->markTestSkipped('The table-listing optimization requires MySQL and Laravel 12+.');
        }

        $connection->enableQueryLog();
        $connection->flushQueryLog();

        try {
            $this->assertTrue(
                WaterlineEngineSource::status(throwOnInspectionFailure: true)['v2_operator_surface_available']
            );
            $this->assertCount(1, $connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        config()
            ->set('workflows.v2.run_summary_model', ReadinessProbeWorkflowRunSummary::class);
        $this->assertFalse(
            WaterlineEngineSource::status(throwOnInspectionFailure: true)['v2_operator_surface_available']
        );

        Schema::create('readiness_probe_run_summaries', static function (Blueprint $table): void {
            $table->string('id')
                ->primary();
        });
        $this->assertTrue(
            WaterlineEngineSource::status(throwOnInspectionFailure: true)['v2_operator_surface_available']
        );

        Schema::drop('readiness_probe_run_summaries');
        $this->assertFalse(
            WaterlineEngineSource::status(throwOnInspectionFailure: true)['v2_operator_surface_available']
        );
    }
}

final class ReadinessProbeWorkflowRunSummary extends WorkflowRunSummary
{
    protected $table = 'readiness_probe_run_summaries';
}
