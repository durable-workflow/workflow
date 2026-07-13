<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Support\HistoryBudget;
use Workflow\V2\Support\WorkerHistoryPayloadContract;

final class WorkerHistoryPayloadContractTest extends TestCase
{
    public function testProjectsEveryCanonicalBudgetValueWithoutAliases(): void
    {
        $budget = [
            'history_event_count' => 37,
            'history_size_bytes' => 8192,
            'history_fan_out' => 12,
            'continue_as_new_recommended' => true,
            'pressure' => HistoryBudget::PRESSURE_CONTINUE_AS_NEW_RECOMMENDED,
            'pressure_dimensions' => [
                HistoryBudget::DIMENSION_EVENT_COUNT,
                HistoryBudget::DIMENSION_SIZE_BYTES,
                HistoryBudget::DIMENSION_FAN_OUT,
            ],
        ];

        $this->assertSame([
            'total_history_events' => 37,
            'history_size_bytes' => 8192,
            'history_fan_out' => 12,
            'continue_as_new_recommended' => true,
            'history_budget_pressure' => HistoryBudget::PRESSURE_CONTINUE_AS_NEW_RECOMMENDED,
            'history_budget_pressure_dimensions' => [
                HistoryBudget::DIMENSION_EVENT_COUNT,
                HistoryBudget::DIMENSION_SIZE_BYTES,
                HistoryBudget::DIMENSION_FAN_OUT,
            ],
        ], WorkerHistoryPayloadContract::fromBudget($budget));
    }

    public function testManifestRequiresTheCompleteBudgetOnBothResponseKinds(): void
    {
        $manifest = WorkerHistoryPayloadContract::manifest();

        $this->assertSame(
            'durable-workflow.v2.worker-history-payload.contract',
            $manifest['schema'],
        );
        $this->assertSame(1, $manifest['version']);
        $this->assertSame(
            WorkerHistoryPayloadContract::BUDGET_FIELDS,
            $manifest['fields'],
        );

        foreach (WorkerHistoryPayloadContract::BUDGET_FIELDS as $field) {
            $this->assertContains($field, $manifest['full_response_required_fields']);
            $this->assertContains($field, $manifest['paginated_response_required_fields']);
        }

        $this->assertSame([
            HistoryBudget::DIMENSION_EVENT_COUNT,
            HistoryBudget::DIMENSION_SIZE_BYTES,
            HistoryBudget::DIMENSION_FAN_OUT,
        ], $manifest['pressure_dimension_values']);
    }
}
