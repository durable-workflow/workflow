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
    }

    public function testExplicitEngineSelectionOverridesAutoDetection(): void
    {
        $this->assertSame(WaterlineEngineSource::ENGINE_V1, WaterlineEngineSource::resolve('v1'));
        $this->assertSame(WaterlineEngineSource::ENGINE_V2, WaterlineEngineSource::resolve('v2'));
    }

    public function testAutoFallsBackToV1WhenConfiguredSummaryTableIsMissing(): void
    {
        config()->set('workflows.v2.run_summary_model', MissingWorkflowRunSummary::class);

        $this->assertFalse(WaterlineEngineSource::v2OperatorSurfaceAvailable());
        $this->assertSame(WaterlineEngineSource::ENGINE_V1, WaterlineEngineSource::resolve());
        $this->assertSame(WaterlineEngineSource::ENGINE_V1, WaterlineEngineSource::resolve('auto'));
    }

    public function testAutoUsesConfiguredSummaryTableWhenItExists(): void
    {
        Schema::create('configured_workflow_run_summaries', static function (Blueprint $table): void {
            $table->string('id')->primary();
        });

        config()->set('workflows.v2.run_summary_model', ConfiguredWorkflowRunSummary::class);

        $this->assertTrue(WaterlineEngineSource::v2OperatorSurfaceAvailable());
        $this->assertSame(WaterlineEngineSource::ENGINE_V2, WaterlineEngineSource::resolve());
    }
}

final class MissingWorkflowRunSummary extends WorkflowRunSummary
{
    protected $table = 'missing_workflow_run_summaries';
}

final class ConfiguredWorkflowRunSummary extends WorkflowRunSummary
{
    protected $table = 'configured_workflow_run_summaries';
}
