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
        $this->assertSame(
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
            $summary['history_pagination']['default_page_size']
        );
        $this->assertSame(
            WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            $summary['history_pagination']['max_page_size']
        );
    }

    public function testDefaultHistoryPageSizeIsReasonable(): void
    {
        $this->assertGreaterThan(0, WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE);
        $this->assertLessThanOrEqual(
            WorkerProtocolVersion::MAX_HISTORY_PAGE_SIZE,
            WorkerProtocolVersion::DEFAULT_HISTORY_PAGE_SIZE,
        );
    }

    public function testSupportedHistoryEncodingsAreFrozen(): void
    {
        $this->assertSame(['gzip', 'deflate'], WorkerProtocolVersion::supportedHistoryEncodings());
    }

    public function testCompressionThresholdIsPositive(): void
    {
        $this->assertGreaterThan(0, WorkerProtocolVersion::COMPRESSION_THRESHOLD);
    }

    public function testLongPollSemanticsContainsAllFields(): void
    {
        $semantics = WorkerProtocolVersion::longPollSemantics();

        $this->assertArrayHasKey('default_timeout_seconds', $semantics);
        $this->assertArrayHasKey('min_timeout_seconds', $semantics);
        $this->assertArrayHasKey('max_timeout_seconds', $semantics);
        $this->assertGreaterThan(0, $semantics['default_timeout_seconds']);
        $this->assertGreaterThan(0, $semantics['min_timeout_seconds']);
        $this->assertGreaterThanOrEqual($semantics['min_timeout_seconds'], $semantics['default_timeout_seconds']);
        $this->assertLessThanOrEqual($semantics['max_timeout_seconds'], $semantics['default_timeout_seconds']);
    }

    public function testClampLongPollTimeoutClampsBelowMinimum(): void
    {
        $this->assertSame(
            WorkerProtocolVersion::MIN_LONG_POLL_TIMEOUT,
            WorkerProtocolVersion::clampLongPollTimeout(0),
        );
    }

    public function testClampLongPollTimeoutClampsAboveMaximum(): void
    {
        $this->assertSame(
            WorkerProtocolVersion::MAX_LONG_POLL_TIMEOUT,
            WorkerProtocolVersion::clampLongPollTimeout(999),
        );
    }

    public function testClampLongPollTimeoutPassesThroughValidValue(): void
    {
        $this->assertSame(15, WorkerProtocolVersion::clampLongPollTimeout(15));
    }

    public function testDescribeIncludesCompressionAndLongPoll(): void
    {
        $summary = WorkerProtocolVersion::describe();

        $this->assertArrayHasKey('history_compression', $summary);
        $this->assertSame(
            WorkerProtocolVersion::supportedHistoryEncodings(),
            $summary['history_compression']['supported_encodings'],
        );
        $this->assertSame(
            WorkerProtocolVersion::COMPRESSION_THRESHOLD,
            $summary['history_compression']['compression_threshold'],
        );

        $this->assertArrayHasKey('long_poll', $summary);
        $this->assertSame(WorkerProtocolVersion::longPollSemantics(), $summary['long_poll']);
    }
}
