<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Support\WaterlineEngineSource;

final class WaterlineEngineSourceTest extends TestCase
{
    public function testAutoResolvesToV2WhenRequiredOperatorTablesExist(): void
    {
        $this->assertTrue(WaterlineEngineSource::v2OperatorSurfaceAvailable());
        $this->assertSame(WaterlineEngineSource::ENGINE_V2, WaterlineEngineSource::resolve());
        $this->assertSame(WaterlineEngineSource::ENGINE_V2, WaterlineEngineSource::resolve('auto'));
        $this->assertSame(WaterlineEngineSource::ENGINE_V2, WaterlineEngineSource::resolve('AUTO'));
        $this->assertSame('v2_auto', WaterlineEngineSource::status()['status']);
        $this->assertTrue(WaterlineEngineSource::status()['uses_v2']);
    }

    public function testExplicitEngineSelectionOverridesAutoDetection(): void
    {
        $this->assertSame(WaterlineEngineSource::ENGINE_V1, WaterlineEngineSource::resolve('v1'));
        $this->assertSame(WaterlineEngineSource::ENGINE_V2, WaterlineEngineSource::resolve('v2'));
        $this->assertSame('v1_pinned', WaterlineEngineSource::status('v1')['status']);
        $this->assertSame('v2_pinned', WaterlineEngineSource::status('v2')['status']);
    }

    public function testAutoFallsBackToV1WhenConfiguredSummaryTableIsMissing(): void
    {
        config()->set('workflows.v2.run_summary_model', MissingWaterlineEngineSourceWorkflowRunSummary::class);

        $this->assertFalse(WaterlineEngineSource::v2OperatorSurfaceAvailable());
        $this->assertSame(WaterlineEngineSource::ENGINE_V1, WaterlineEngineSource::resolve());
        $this->assertSame(WaterlineEngineSource::ENGINE_V1, WaterlineEngineSource::resolve('auto'));
        $status = WaterlineEngineSource::status();

        $this->assertSame('auto_fallback_to_v1', $status['status']);
        $this->assertFalse($status['uses_v2']);
        $this->assertSame('missing_table', $status['issues'][0]['reason']);
        $this->assertSame(MissingWaterlineEngineSourceWorkflowRunSummary::class, $status['issues'][0]['model']);
        $this->assertSame('missing_workflow_run_summaries', $status['issues'][0]['table']);
    }

    public function testExplicitV2StatusRemainsPinnedButUnavailableWhenRequiredTableIsMissing(): void
    {
        config()->set('workflows.v2.run_summary_model', MissingWaterlineEngineSourceWorkflowRunSummary::class);

        $status = WaterlineEngineSource::status('v2');

        $this->assertSame(WaterlineEngineSource::ENGINE_V2, WaterlineEngineSource::resolve('v2'));
        $this->assertSame('v2_pinned_unavailable', $status['status']);
        $this->assertSame(WaterlineEngineSource::ENGINE_V2, $status['resolved']);
        $this->assertFalse($status['uses_v2']);
        $this->assertFalse($status['v2_operator_surface_available']);
    }

    public function testAutoUsesConfiguredSummaryTableWhenItExists(): void
    {
        Schema::create('configured_workflow_run_summaries', static function (Blueprint $table): void {
            $table->string('id')
                ->primary();
        });

        config()
            ->set('workflows.v2.run_summary_model', ConfiguredWaterlineEngineSourceWorkflowRunSummary::class);

        $this->assertTrue(WaterlineEngineSource::v2OperatorSurfaceAvailable());
        $this->assertSame(WaterlineEngineSource::ENGINE_V2, WaterlineEngineSource::resolve());
        $this->assertSame(
            'configured_workflow_run_summaries',
            WaterlineEngineSource::status()['required_tables'][10]['table']
        );
    }
}

final class MissingWaterlineEngineSourceWorkflowRunSummary extends WorkflowRunSummary
{
    protected $table = 'missing_workflow_run_summaries';
}

final class ConfiguredWaterlineEngineSourceWorkflowRunSummary extends WorkflowRunSummary
{
    protected $table = 'configured_workflow_run_summaries';
}
