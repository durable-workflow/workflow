<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Fixtures\TestVersionedActivityV1;
use Tests\Fixtures\TestVersionedActivityV3;
use Tests\Fixtures\TestVersionMinSupportedWorkflow;
use Tests\Fixtures\TestVersionWorkflow;
use Tests\TestCase;
use Workflow\Exceptions\VersionNotSupportedException;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\WorkflowStub;

final class VersionWorkflowTest extends TestCase
{
    public function testNewWorkflowUsesMaxVersion(): void
    {
        $workflow = WorkflowStub::make(TestVersionWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());

        $output = $workflow->output();
        $this->assertSame(2, $output['version1']);
        $this->assertSame('v3_result', $output['result1']);
        $this->assertSame(1, $output['version2']);
        $this->assertSame('new_path', $output['result2']);
    }

    public function testVersionIsRecordedInLogs(): void
    {
        $workflow = WorkflowStub::make(TestVersionWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $logs = $workflow->logs()
            ->pluck('class')
            ->toArray();

        $this->assertContains('version:step-1', $logs);
        $this->assertContains('version:step-2', $logs);
        $this->assertContains(TestVersionedActivityV3::class, $logs);
    }

    public function testVersionIsReplayedFromLogs(): void
    {
        $workflow = WorkflowStub::make(TestVersionWorkflow::class);
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => 'version:step-1',
                'result' => Serializer::serialize(1),
            ]);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());

        $output = $workflow->output();

        $this->assertSame(1, $output['version1']);
        $this->assertSame('v2_result', $output['result1']);
    }

    public function testDefaultVersionIsReplayedFromLogs(): void
    {
        $workflow = WorkflowStub::make(TestVersionWorkflow::class);
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => 'version:step-1',
                'result' => Serializer::serialize(WorkflowStub::DEFAULT_VERSION),
            ]);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());

        $output = $workflow->output();

        $this->assertSame(WorkflowStub::DEFAULT_VERSION, $output['version1']);
        $this->assertSame('v1_result', $output['result1']);
    }

    public function testGetVersionAddedToExistingHistoryDoesNotShiftReplay(): void
    {
        $workflow = WorkflowStub::make(TestVersionWorkflow::class);
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

        // Simulate a workflow whose history was recorded before the getVersion('step-1')
        // call was introduced: index 0 holds the first activity's result rather than a
        // version marker, so getVersion() must not consume that slot.
        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => TestVersionedActivityV1::class,
                'result' => Serializer::serialize('v1_result'),
            ]);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());

        $output = $workflow->output();

        // getVersion() falls back to the default version because the change did not
        // exist when this history was recorded...
        $this->assertSame(WorkflowStub::DEFAULT_VERSION, $output['version1']);
        // ...and because the slot was left untouched, the first activity still reads
        // its own recorded result instead of a shifted event.
        $this->assertSame('v1_result', $output['result1']);
    }

    public function testVersionBelowMinSupportedThrowsException(): void
    {
        $workflow = WorkflowStub::make(TestVersionMinSupportedWorkflow::class);
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => 'version:step-1',
                'result' => Serializer::serialize(WorkflowStub::DEFAULT_VERSION),
            ]);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowFailedStatus::class, $workflow->status());
        $this->assertNotNull($workflow->exceptions()->first());

        $exception = Serializer::unserialize($workflow->exceptions()->first()->exception);
        $this->assertSame(VersionNotSupportedException::class, $exception['class']);
        $this->assertStringContainsString("Version -1 for change ID 'step-1' is not supported", $exception['message']);
    }

    public function testVersionAboveMaxSupportedThrowsException(): void
    {
        $workflow = WorkflowStub::make(TestVersionWorkflow::class);
        $storedWorkflow = StoredWorkflow::findOrFail($workflow->id());

        $storedWorkflow->logs()
            ->create([
                'index' => 0,
                'now' => now(),
                'class' => 'version:step-1',
                'result' => Serializer::serialize(99),
            ]);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowFailedStatus::class, $workflow->status());
        $this->assertNotNull($workflow->exceptions()->first());

        $exception = Serializer::unserialize($workflow->exceptions()->first()->exception);
        $this->assertSame(VersionNotSupportedException::class, $exception['class']);
        $this->assertStringContainsString("Version 99 for change ID 'step-1' is not supported", $exception['message']);
    }

    public function testMultipleVersionChangePoints(): void
    {
        $workflow = WorkflowStub::make(TestVersionWorkflow::class);

        $workflow->start();

        while ($workflow->running());

        $this->assertSame(WorkflowCompletedStatus::class, $workflow->status());

        $versionLogs = $workflow->logs()
            ->filter(static fn ($log) => str_starts_with($log->class, 'version:'))
            ->values();

        $this->assertCount(2, $versionLogs);
        $this->assertSame('version:step-1', $versionLogs[0]->class);
        $this->assertSame('version:step-2', $versionLogs[1]->class);
    }
}
