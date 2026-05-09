<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Contracts\WorkflowControlPlane;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Support\WorkerCompatibility;
use Workflow\V2\Support\WorkerCompatibilityFleet;

/**
 * Replay 2026 worker-versioning parity: a server (or other caller)
 * driving a start through the control plane must be able to pin the
 * new run to a specific worker build id, so subsequent worker pools
 * running a different build cannot break replay.
 *
 * The pin is expressed as the `build_id` option on the start
 * contract; the legacy `WorkerCompatibility::current()` config
 * fallback is preserved when no pin is supplied.
 */
final class V2WorkflowVersionPinningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('workflows.v2.compatibility.namespace', null);
        config()
            ->set('workflows.v2.types.workflows', [
                'test-greeting-workflow' => TestGreetingWorkflow::class,
            ]);
        WorkerCompatibilityFleet::clear();
        Queue::fake();
    }

    public function test_start_option_pins_run_and_first_task_to_supplied_build_id(): void
    {
        // No worker context — WorkerCompatibility::current() returns null,
        // so without an explicit pin the run would stay unversioned.
        $this->assertNull(WorkerCompatibility::current());

        $controlPlane = $this->app->make(WorkflowControlPlane::class);

        $result = $controlPlane->start('test-greeting-workflow', 'pin-by-build-id', [
            'build_id' => 'v2026.05.01-rc1',
            'arguments' => null,
        ]);

        $this->assertTrue($result['started']);

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'pin-by-build-id')
            ->firstOrFail();

        $this->assertSame('v2026.05.01-rc1', $run->compatibility);

        $task = WorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->firstOrFail();

        $this->assertSame('v2026.05.01-rc1', $task->compatibility);
    }

    public function test_start_option_accepts_compatibility_alias(): void
    {
        $controlPlane = $this->app->make(WorkflowControlPlane::class);

        $result = $controlPlane->start('test-greeting-workflow', 'pin-by-compat', [
            'compatibility' => 'v2026.05.01-rc1',
            'arguments' => null,
        ]);

        $this->assertTrue($result['started']);

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'pin-by-compat')
            ->firstOrFail();

        $this->assertSame('v2026.05.01-rc1', $run->compatibility);
    }

    public function test_start_falls_back_to_worker_compatibility_current_when_no_pin_supplied(): void
    {
        config()
            ->set('workflows.v2.compatibility.current', 'build-from-worker-context');

        $controlPlane = $this->app->make(WorkflowControlPlane::class);

        $controlPlane->start('test-greeting-workflow', 'pin-fallback', [
            'arguments' => null,
        ]);

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'pin-fallback')
            ->firstOrFail();

        $this->assertSame('build-from-worker-context', $run->compatibility);
    }

    public function test_explicit_pin_overrides_worker_compatibility_current(): void
    {
        config()
            ->set('workflows.v2.compatibility.current', 'build-from-worker-context');

        $controlPlane = $this->app->make(WorkflowControlPlane::class);

        $controlPlane->start('test-greeting-workflow', 'pin-overrides', [
            'build_id' => 'operator-routed-build',
            'arguments' => null,
        ]);

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'pin-overrides')
            ->firstOrFail();

        $this->assertSame('operator-routed-build', $run->compatibility);
    }

    public function test_blank_pin_value_falls_through_to_legacy_resolution(): void
    {
        config()
            ->set('workflows.v2.compatibility.current', 'build-from-worker-context');

        $controlPlane = $this->app->make(WorkflowControlPlane::class);

        $controlPlane->start('test-greeting-workflow', 'pin-blank', [
            'build_id' => '   ',
            'arguments' => null,
        ]);

        $run = WorkflowRun::query()
            ->where('workflow_instance_id', 'pin-blank')
            ->firstOrFail();

        $this->assertSame('build-from-worker-context', $run->compatibility);
    }
}
