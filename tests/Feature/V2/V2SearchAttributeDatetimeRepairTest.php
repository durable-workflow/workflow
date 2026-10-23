<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\V2\TestGreetingActivity;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\HistoryProjectionMaintenanceRole;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\WorkflowStub;

final class V2SearchAttributeDatetimeRepairTest extends TestCase
{
    #[DataProvider('offsetDatetimes')]
    public function testPreviewApplyAndRepeatedApplyPreserveBusinessState(string $original): void
    {
        $run = $this->legacyRun(['Offset' => $original]);
        $business = $this->businessSnapshot($run);
        $before = $this->attributes($run);

        $this->assertSame(0, $this->command($run));
        $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($preview['dry_run']);
        $this->assertSame(1, $preview['would_repair']);
        $this->assertSame(0, $preview['repaired']);
        $this->assertSame($before, $this->attributes($run));

        $this->assertSame(0, $this->command($run, ['--apply' => true]));
        $applied = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $applied['repaired']);
        $this->assertSame(
            '2026-10-08T10:34:56.123456Z',
            $run->searchAttributes()->firstOrFail()->getValue()?->toISOString(),
        );
        $this->assertSame($business, $this->businessSnapshot($run));

        $repaired = $this->attributes($run);
        $this->assertSame(0, $this->command($run, ['--apply' => true]));
        $repeat = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $repeat['repaired']);
        $this->assertSame(1, $repeat['already_correct']);
        $this->assertSame($repaired, $this->attributes($run));
        $this->assertSame($business, $this->businessSnapshot($run));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function offsetDatetimes(): iterable
    {
        yield 'positive offset' => ['2026-10-08T12:34:56.123456+02:00'];
        yield 'negative offset' => ['2026-10-08T06:34:56.123456-04:00'];
        yield 'next local day' => ['2026-10-09T00:34:56.123456+14:00'];
        yield 'previous local day' => ['2026-10-07T22:34:56.123456-12:00'];
    }

    public function testMissingTimezoneRefusesTheRunWithoutGuessingOrPartialWrites(): void
    {
        $run = $this->legacyRun([
            'Recoverable' => '2026-10-08T12:34:56.123456+02:00',
            'Unknown' => '2026-10-08T12:34:56.123456',
        ]);
        $before = $this->attributes($run);
        $business = $this->businessSnapshot($run);

        $this->assertSame(1, $this->command($run, ['--apply' => true]));
        $this->assertSame($before, $this->attributes($run));
        $this->assertSame($business, $this->businessSnapshot($run));

        $this->assertSame(0, $this->command($run, ['--apply' => true, '--key' => ['Recoverable']]));
        $rows = $run->searchAttributes()->get()->keyBy('key');
        $this->assertSame('2026-10-08 10:34:56.123456', $rows['Recoverable']->getRawOriginal('value_datetime'));
        $this->assertSame('2026-10-08 12:34:56.123456', $rows['Unknown']->getRawOriginal('value_datetime'));
    }

    public function testLaterCanonicalUpsertFencesAStaleProjection(): void
    {
        $run = $this->legacyRun(['Offset' => '2026-10-08T12:34:56.123456+02:00']);
        WorkflowHistoryEvent::record($run, HistoryEventType::SearchAttributesUpserted, [
            'sequence' => 8,
            'attributes' => ['Offset' => '2026-10-09T10:00:00Z'],
            'attribute_types' => ['Offset' => WorkflowSearchAttribute::TYPE_DATETIME],
            'merged' => ['Offset' => '2026-10-09T10:00:00Z'],
        ]);
        $before = $this->attributes($run);
        $business = $this->businessSnapshot($run);

        $this->assertSame(1, $this->command($run, ['--apply' => true]));
        $this->assertSame($before, $this->attributes($run));
        $this->assertSame($business, $this->businessSnapshot($run));
    }

    public function testProjectionFailureRollsBackEveryAttributeWrite(): void
    {
        $run = $this->legacyRun([
            'First' => '2026-10-08T12:34:56.123456+02:00',
            'Second' => '2026-10-08T06:34:56.123456-04:00',
        ]);
        $before = $this->attributes($run);
        $business = $this->businessSnapshot($run);
        $this->mock(HistoryProjectionMaintenanceRole::class, static function (MockInterface $mock): void {
            $mock->shouldReceive('projectRun')->once()->andThrow(new RuntimeException('Projection write failed'));
        });

        $this->assertSame(1, $this->command($run, ['--apply' => true]));
        $this->assertSame($before, $this->attributes($run));
        $this->assertSame($business, $this->businessSnapshot($run));
    }

    public function testNamespaceAndPrunedHistoryRefusalsDoNotMutateRows(): void
    {
        $run = $this->legacyRun(['Offset' => '2026-10-08T12:34:56.123456+02:00']);
        $before = $this->attributes($run);
        $this->assertSame(1, $this->command($run, ['--namespace' => 'other', '--apply' => true]));
        $this->assertSame($before, $this->attributes($run));

        $run->forceFill(['details_pruned_at' => now()])->save();
        $business = $this->businessSnapshot($run);
        $this->assertSame(1, $this->command($run, ['--apply' => true]));
        $this->assertSame($before, $this->attributes($run));
        $this->assertSame($business, $this->businessSnapshot($run));
    }

    /**
     * @param array<string, string> $originals
     */
    private function legacyRun(array $originals): WorkflowRun
    {
        WorkflowStub::fake();
        WorkflowStub::mock(TestGreetingActivity::class, 'Hello, Taylor!');
        $workflow = WorkflowStub::make(TestGreetingWorkflow::class, 'datetime-repair');
        $workflow->start('Taylor');
        $run = WorkflowRun::query()->findOrFail($workflow->runId());

        foreach ($originals as $key => $original) {
            $attribute = new WorkflowSearchAttribute([
                'workflow_run_id' => $run->id,
                'workflow_instance_id' => $run->workflow_instance_id,
                'key' => $key,
                'upserted_at_sequence' => 7,
            ]);
            $attribute->setTypedValue($original, WorkflowSearchAttribute::TYPE_DATETIME);
            $attribute->save();
            // Reproduce the original wall-clock SQL representation without altering history.
            $attribute->getConnection()->table($attribute->getTable())
                ->where('id', $attribute->id)
                ->update(['value_datetime' => (new DateTimeImmutable($original))->format('Y-m-d H:i:s.u')]);
        }

        WorkflowHistoryEvent::record($run, HistoryEventType::SearchAttributesUpserted, [
            'sequence' => 7,
            'attributes' => $originals,
            'attribute_types' => array_fill_keys(array_keys($originals), WorkflowSearchAttribute::TYPE_DATETIME),
            'merged' => $originals,
        ]);

        return $run;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function command(WorkflowRun $run, array $options = []): int
    {
        return Artisan::call('workflow:v2:repair-search-datetimes', array_merge([
            'run' => $run->id,
            '--namespace' => $run->namespace,
            '--json' => true,
        ], $options));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attributes(WorkflowRun $run): array
    {
        return $run->getConnection()->table('workflow_search_attributes')
            ->where('workflow_run_id', $run->id)->orderBy('key')->get()
            ->map(static fn (object $row): array => (array) $row)->all();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function businessSnapshot(WorkflowRun $run): array
    {
        $snapshot = [];

        foreach (['workflow_runs', 'workflow_tasks', 'workflow_history_events', 'workflow_commands'] as $table) {
            $snapshot[$table] = $run->getConnection()->table($table)
                ->where($table === 'workflow_runs' ? 'id' : 'workflow_run_id', $run->id)
                ->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }
}
