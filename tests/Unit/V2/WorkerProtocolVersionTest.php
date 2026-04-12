<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Support\WorkerProtocolVersion;

final class WorkerProtocolVersionTest extends TestCase
{
    public function testVersionIsNonEmptyString(): void
    {
        $this->assertNotEmpty(WorkerProtocolVersion::VERSION);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', WorkerProtocolVersion::VERSION);
    }

    public function testWorkflowTaskVerbsIncludesAllBridgeMethods(): void
    {
        $verbs = WorkerProtocolVersion::workflowTaskVerbs();

        $this->assertContains('poll', $verbs);
        $this->assertContains('claim', $verbs);
        $this->assertContains('claimStatus', $verbs);
        $this->assertContains('historyPayload', $verbs);
        $this->assertContains('historyPayloadPaginated', $verbs);
        $this->assertContains('execute', $verbs);
        $this->assertContains('complete', $verbs);
        $this->assertContains('fail', $verbs);
        $this->assertContains('heartbeat', $verbs);
    }

    public function testActivityTaskVerbsIncludesAllBridgeMethods(): void
    {
        $verbs = WorkerProtocolVersion::activityTaskVerbs();

        $this->assertContains('poll', $verbs);
        $this->assertContains('claim', $verbs);
        $this->assertContains('claimStatus', $verbs);
        $this->assertContains('complete', $verbs);
        $this->assertContains('fail', $verbs);
        $this->assertContains('status', $verbs);
        $this->assertContains('heartbeat', $verbs);
    }

    public function testNonTerminalCommandTypesAreFrozen(): void
    {
        $this->assertSame([
            'schedule_activity',
            'start_timer',
            'start_child_workflow',
            'record_side_effect',
            'record_version_marker',
            'upsert_search_attributes',
        ], WorkerProtocolVersion::nonTerminalCommandTypes());
    }

    public function testTerminalCommandTypesAreFrozen(): void
    {
        $this->assertSame([
            'complete_workflow',
            'fail_workflow',
            'continue_as_new',
        ], WorkerProtocolVersion::terminalCommandTypes());
    }

    public function testDescribeReturnsFullProtocolSummary(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertSame(WorkerProtocolVersion::VERSION, $summary['version']);
        $this->assertSame(WorkerProtocolVersion::workflowTaskVerbs(), $summary['workflow_task_verbs']);
        $this->assertSame(WorkerProtocolVersion::activityTaskVerbs(), $summary['activity_task_verbs']);
        $this->assertSame(WorkerProtocolVersion::nonTerminalCommandTypes(), $summary['non_terminal_command_types']);
        $this->assertSame(WorkerProtocolVersion::terminalCommandTypes(), $summary['terminal_command_types']);
        $this->assertSame(WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE, $summary['history_pagination']['default_page_size']);
        $this->assertSame(WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE, $summary['history_pagination']['max_page_size']);
    }

    public function testDefaultHistoryPageSizeIsReasonable(): void
    {
        $this->assertGreaterThan(0, WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE);
        $this->assertLessThanOrEqual(
            WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
        );
    }
}
