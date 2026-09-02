<?php

declare(strict_types=1);

namespace Tests\Unit\States;

use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;
use Workflow\States\HasStates;
use Workflow\States\State;
use Workflow\States\StateConfig;
use Workflow\States\WorkflowCreatedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\States\WorkflowRunningStatus;
use Workflow\States\WorkflowStatus;

final class HasStatesTest extends TestCase
{
    public function testDefaultsAndStateLookupMethods(): void
    {
        $defaults = HasStatesCoverageModel::getDefaultStates();

        $this->assertSame(WorkflowCreatedStatus::$name, $defaults->get('status'));
        $this->assertNull($defaults->get('aux_state'));
        $this->assertSame(WorkflowCreatedStatus::$name, HasStatesCoverageModel::getDefaultStateFor('status'));
        $this->assertNull(HasStatesCoverageModel::getDefaultStateFor('missing'));

        $statusStates = HasStatesCoverageModel::getStatesFor('status')->all();
        $this->assertContains(WorkflowCreatedStatus::$name, $statusStates);
        $this->assertContains(WorkflowRunningStatus::$name, $statusStates);
        $this->assertSame([], HasStatesCoverageModel::getStatesFor('missing')->all());

        $model = HasStatesCoverageModel::make([
            'class' => 'default-state-test',
        ]);

        $this->assertInstanceOf(WorkflowCreatedStatus::class, $model->status);
        $this->assertNull($model->getAttribute('aux_state'));
    }

    public function testQueryScopesUseStateMappings(): void
    {
        HasStatesCoverageModel::create([
            'class' => 'scope-query-test',
            'status' => WorkflowCreatedStatus::class,
        ]);
        HasStatesCoverageModel::create([
            'class' => 'scope-query-test',
            'status' => WorkflowRunningStatus::class,
        ]);
        HasStatesCoverageModel::create([
            'class' => 'scope-query-test',
            'status' => WorkflowFailedStatus::class,
        ]);

        $this->assertSame(
            1,
            HasStatesCoverageModel::query()
                ->where('class', 'scope-query-test')
                ->whereState('status', WorkflowRunningStatus::$name)
                ->count()
        );
        $this->assertSame(
            2,
            HasStatesCoverageModel::query()
                ->where('class', 'scope-query-test')
                ->whereNotState('status', WorkflowRunningStatus::class)
                ->count()
        );
        $this->assertSame(
            2,
            HasStatesCoverageModel::query()
                ->where('class', 'scope-query-test')
                ->whereState('status', WorkflowCreatedStatus::$name)
                ->orWhereState('status', WorkflowRunningStatus::class)
                ->count()
        );
        $this->assertSame(
            3,
            HasStatesCoverageModel::query()
                ->where('class', 'scope-query-test')
                ->whereState('status', WorkflowCreatedStatus::$name)
                ->orWhereNotState('status', WorkflowCreatedStatus::class)
                ->count()
        );

        $sql = HasStatesCoverageModel::query()
            ->whereState('unknown_state', WorkflowRunningStatus::class)
            ->toSql();

        $this->assertStringContainsString('0 = 1', $sql);
    }
}

abstract class HasStatesCoverageAuxState extends State
{
    public static function config(): StateConfig
    {
        return parent::config()
            ->allowTransition(HasStatesCoverageAuxActiveState::class, HasStatesCoverageAuxPausedState::class);
    }
}

final class HasStatesCoverageAuxActiveState extends HasStatesCoverageAuxState
{
    public static string $name = 'aux-active';
}

final class HasStatesCoverageAuxPausedState extends HasStatesCoverageAuxState
{
    public static string $name = 'aux-paused';
}

final class HasStatesCoverageModel extends Model
{
    use HasStates;

    protected $table = 'workflows';

    protected $guarded = [];

    protected $casts = [
        'status' => WorkflowStatus::class,
        'aux_state' => HasStatesCoverageAuxState::class,
    ];
}
