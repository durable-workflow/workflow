<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\V2\TestRedriveWorkflow;
use Tests\TestCase;
use Workflow\V2\Support\FailedRunRedrivePlan;
use Workflow\V2\WorkflowStub;

final class V2FailedRunRedrivePlanTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'sync');
        config()
            ->set('queue.connections.sync.driver', 'sync');
        Cache::forget('test:redrive:first-calls');
        Cache::forget('test:redrive:second-calls');
    }

    public function testRealFailedRunHasAValidatedCompletedActivityPrefix(): void
    {
        WorkflowStub::fake();

        $workflow = WorkflowStub::make(TestRedriveWorkflow::class, 'redrive-plan-1');
        $workflow->start('Taylor');

        $this->assertTrue($workflow->refresh()->failed());
        $this->assertSame(1, (int) Cache::get('test:redrive:first-calls'));
        $this->assertSame(1, (int) Cache::get('test:redrive:second-calls'));

        $plan = FailedRunRedrivePlan::forRun($workflow->run()->fresh());

        $this->assertTrue($plan['eligible'], (string) $plan['reason']);
        $this->assertSame(2, $plan['resume_step_sequence']);
        $this->assertCount(1, $plan['completed']);
        $this->assertSame(1, $plan['completed'][0]['sequence']);
    }
}
