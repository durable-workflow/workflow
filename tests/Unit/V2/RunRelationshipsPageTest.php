<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\DefaultOperatorObservabilityRepository;
use Workflow\V2\Support\RunRelationshipsPage;

final class RunRelationshipsPageTest extends TestCase
{
    public function testLargeCoordinatorReadsOnlyItsLinkPageAndMetadataWithIndependentChildOutcomes(): void
    {
        $parent = $this->createRun('parent', 'namespace-a', 'completed');
        for ($i = 1; $i <= 101; ++$i) {
            $child = $this->createRun('child-' . $i, 'namespace-a', $i === 1 ? 'failed' : 'running');
            $this->link(sprintf('link-%03d', $i), $parent, $child);
        }
        $unrelated = $this->createRun('unrelated', 'namespace-b');
        $rows = [];
        for ($i = 1; $i <= 1001; ++$i) {
            $rows[] = [
                'id' => 'history-' . $i,
                'workflow_run_id' => 'child-1',
                'sequence' => $i,
                'event_type' => 'SignalReceived',
                'payload' => '{}',
                'recorded_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('workflow_history_events')->insert($chunk);
        }
        $this->link('unrelated-link', $unrelated, $parent);
        $retrieved = [
            'links' => 0,
            'runs' => 0,
            'history' => 0,
        ];
        foreach ([
            'links' => WorkflowLink::class,
            'runs' => WorkflowRun::class,
            'history' => WorkflowHistoryEvent::class,
        ] as $key => $model) {
            $model::retrieved(static function () use (&$retrieved, $key): void {
                ++$retrieved[$key];
            });
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page = (new DefaultOperatorObservabilityRepository())->runRelationshipsPage($parent);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame([
            'links' => 51,
            'runs' => 50,
            'history' => 0,
        ], $retrieved);
        $this->assertCount(3, $queries);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select ', strtolower($query['query']));
            $this->assertStringNotContainsString('workflow_history_events', $query['query']);
        }
        $this->assertCount(50, $page['relationships']);
        $this->assertTrue($page['has_more']);
        $this->assertSame('link-050', $page['next_link_id']);
        $this->assertSame('link-101', $page['through_link_id']);
        $this->assertSame('failed', $page['relationships'][0]['status']);
        $this->assertSame('running', $page['relationships'][1]['status']);
        $this->assertTrue($page['relationships'][0]['is_terminal']);
        $this->assertFalse($page['relationships'][1]['is_terminal']);
        $this->assertSame('workflow_runs', $page['status_source']);
        $this->assertSame('not_evaluated', $page['history_audit']);
        $this->assertSame([], $parent->getRelations());
        $this->assertSame(0, DB::table('workflow_run_lineage_entries')->count());
    }

    public function testPaginationKeepsTheOriginalLinkBoundaryWhileMetadataReflectsCurrentRuns(): void
    {
        $parent = $this->createRun('parent');
        $one = $this->createRun('one');
        $two = $this->createRun('two');
        $three = $this->createRun('three');
        $this->link('001', $parent, $one);
        $this->link('002', $parent, $two);
        $first = RunRelationshipsPage::forRun($parent, limit: 1);
        $this->link('003', $parent, $three);
        $two->forceFill([
            'status' => 'completed',
            'details_pruned_at' => now(),
        ])->save();
        $second = RunRelationshipsPage::forRun(
            $parent,
            limit: 1,
            afterLinkId: $first['next_link_id'],
            throughLinkId: $first['through_link_id'],
        );

        $this->assertSame(['002'], array_column($second['relationships'], 'link_id'));
        $this->assertFalse($second['has_more']);
        $this->assertNull($second['next_link_id']);
        $this->assertSame('completed', $second['relationships'][0]['status']);
        $this->assertSame('pruned', $second['relationships'][0]['details_state']);
        $fresh = RunRelationshipsPage::forRun($parent, afterLinkId: '002');
        $this->assertSame(['003'], array_column($fresh['relationships'], 'link_id'));
    }

    public function testMissingCrossNamespaceAndInconsistentInstanceMetadataStayUnavailable(): void
    {
        $parent = $this->createRun('parent', 'namespace-a');
        $other = $this->createRun('other', 'namespace-b');
        $child = $this->createRun('child', 'namespace-a');
        $this->link('001', $parent, $other);
        $this->link('002', $parent, $child);
        $this->link('003', $parent, $child, 'explicit_link');
        DB::table('workflow_links')->where('id', '002')->update([
            'child_workflow_instance_id' => 'wrong-instance',
        ]);
        DB::table('workflow_links')->where('id', '003')->update([
            'child_workflow_run_id' => 'missing-run',
        ]);

        $page = RunRelationshipsPage::forRun($parent);

        foreach ($page['relationships'] as $relationship) {
            $this->assertSame('unavailable', $relationship['metadata_state']);
            $this->assertNull($relationship['class']);
            $this->assertNull($relationship['status']);
            $this->assertNull($relationship['is_terminal']);
        }
        $this->assertSame('explicit_link', $page['relationships'][2]['link_type']);
        $this->assertSame('other', $page['relationships'][0]['run_id']);
    }

    public function testParentDirectionAndConfiguredRunScopeArePreserved(): void
    {
        $parent = $this->createRun('parent');
        $child = $this->createRun('child');
        $this->link('001', $parent, $child);

        $parents = RunRelationshipsPage::forRun($child, direction: 'parents');
        $this->assertSame('parents', $parents['direction']);
        $this->assertSame('parent', $parents['relationships'][0]['run_id']);
        $this->assertSame('available', $parents['relationships'][0]['metadata_state']);
        config()
            ->set('workflows.v2.run_model', ScopedRelationshipRun::class);
        $historyModels = 0;
        WorkflowHistoryEvent::retrieved(static function () use (&$historyModels): void {
            ++$historyModels;
        });
        $scoped = RunRelationshipsPage::forRun($child, direction: 'parents');
        $this->assertSame('unavailable', $scoped['relationships'][0]['metadata_state']);
        $this->assertNull($scoped['relationships'][0]['class']);
        $this->assertSame(0, $historyModels);
        WorkflowHistoryEvent::query()->create([
            'id' => 'child-event',
            'workflow_run_id' => $child->id,
            'sequence' => 1,
            'event_type' => 'SignalReceived',
            'payload' => [],
            'recorded_at' => now(),
        ]);
        $visibleChild = RunRelationshipsPage::forRun($parent);
        $this->assertSame('available', $visibleChild['relationships'][0]['metadata_state']);
        $this->assertSame(0, $historyModels, 'Configured default eager loads must not fetch a child history.');
    }

    public function testEmptyAndPrunedLinkWindowsDoNotClaimCompleteOriginalRelationships(): void
    {
        $run = $this->createRun('empty');
        $run->forceFill([
            'details_pruned_at' => now(),
        ])->save();

        $page = RunRelationshipsPage::forRun($run);

        $this->assertSame([], $page['relationships']);
        $this->assertFalse($page['has_more']);
        $this->assertSame('', $page['through_link_id']);
        $this->assertSame('pruned', $page['details_state']);
        $this->assertSame('not_evaluated', $page['history_audit']);
    }

    #[DataProvider('invalidPages')]
    public function testInvalidPagesFailBeforeAnyRead(
        string $direction,
        int $limit,
        string $after,
        ?string $through
    ): void {
        $run = $this->createRun('invalid');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            RunRelationshipsPage::forRun($run, $direction, $limit, $after, $through);
            $this->fail('An invalid page was accepted.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    /**
     * @return list<array{string, int, string, ?string}>
     */
    public static function invalidPages(): array
    {
        return [['siblings', 50, '', null], ['children', 0, '', null], ['parents', 101, '', null],
            ['children', 50, str_repeat('a', 27), null], ['children', 50, '', str_repeat('b', 27)]];
    }

    private function createRun(string $id, string $namespace = 'default', string $status = 'running'): WorkflowRun
    {
        WorkflowInstance::query()->create([
            'id' => 'instance-' . $id,
            'namespace' => $namespace,
            'workflow_class' => 'relationship.proof',
            'workflow_type' => 'relationship.proof',
            'run_count' => 1,
        ]);

        return WorkflowRun::query()->create([
            'id' => $id,
            'workflow_instance_id' => 'instance-' . $id,
            'namespace' => $namespace,
            'run_number' => 1,
            'workflow_class' => 'relationship.proof',
            'workflow_type' => 'relationship.proof',
            'status' => $status,
            'connection' => 'database',
            'queue' => 'relationship-proof',
        ]);
    }

    private function link(string $id, WorkflowRun $parent, WorkflowRun $child, string $type = 'child_workflow'): void
    {
        WorkflowLink::query()->create([
            'id' => $id,
            'link_type' => $type,
            'sequence' => 1,
            'parent_workflow_instance_id' => $parent->workflow_instance_id,
            'parent_workflow_run_id' => $parent->id,
            'child_workflow_instance_id' => $child->workflow_instance_id,
            'child_workflow_run_id' => $child->id,
            'is_primary_parent' => true,
        ]);
    }
}

final class ScopedRelationshipRun extends WorkflowRun
{
    protected $with = ['historyEvents', 'childLinks.childRun.historyEvents'];

    protected static function booted(): void
    {
        static::addGlobalScope('relationship-fixture', static function ($query): void {
            $query->where('id', '!=', 'parent');
        });
    }
}
