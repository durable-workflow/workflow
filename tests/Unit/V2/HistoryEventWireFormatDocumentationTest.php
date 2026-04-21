<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\HistoryEventPayloadContract;

/**
 * DB-free guards for the replay-critical history-event schema documented in
 * docs/api-stability.md. These tests pin the documentation and representative
 * PHP emit sites together so wire-format drift gets reviewed deliberately.
 */
final class HistoryEventWireFormatDocumentationTest extends TestCase
{
    /**
     * @var array<string, list<string>>
     */
    private const DOCUMENTED_KEYS = [
        'WorkflowStarted' => [
            'workflow_class',
            'workflow_type',
            'workflow_instance_id',
            'workflow_run_id',
            'workflow_command_id',
            'business_key',
            'visibility_labels',
            'memo',
            'search_attributes',
            'execution_timeout_seconds',
            'run_timeout_seconds',
            'execution_deadline_at',
            'run_deadline_at',
            'workflow_definition_fingerprint',
            'declared_queries',
            'declared_query_contracts',
            'declared_signals',
            'declared_signal_contracts',
            'declared_updates',
            'declared_update_contracts',
            'declared_entry_method',
            'declared_entry_mode',
            'declared_entry_declaring_class',
            'parent_workflow_instance_id',
            'parent_workflow_run_id',
            'parent_sequence',
            'workflow_link_id',
            'child_call_id',
            'retry_policy',
            'timeout_policy',
            'continued_from_run_id',
            'retry_attempt',
            'retry_of_child_workflow_run_id',
        ],
        'ActivityScheduled' => [
            'activity_execution_id',
            'activity_class',
            'activity_type',
            'sequence',
            'activity',
        ],
        'ActivityCompleted' => [
            'activity_execution_id',
            'activity_attempt_id',
            'activity_class',
            'activity_type',
            'sequence',
            'attempt_number',
            'result',
            'payload_codec',
            'activity',
            'parallel_group_path',
        ],
        'TimerScheduled' => [
            'timer_id',
            'sequence',
            'delay_seconds',
            'fire_at',
            'timer_kind',
            'condition_wait_id',
            'condition_key',
            'condition_definition_fingerprint',
            'signal_wait_id',
            'signal_name',
        ],
        'TimerFired' => [
            'timer_id',
            'sequence',
            'delay_seconds',
            'fire_at',
            'fired_at',
            'timer_kind',
            'condition_wait_id',
            'condition_key',
            'condition_definition_fingerprint',
            'signal_wait_id',
            'signal_name',
        ],
        'SignalReceived' => [
            'workflow_command_id',
            'signal_id',
            'workflow_instance_id',
            'workflow_run_id',
            'signal_name',
            'signal_wait_id',
        ],
        'SignalApplied' => [
            'workflow_command_id',
            'signal_id',
            'signal_name',
            'signal_wait_id',
            'sequence',
            'value',
        ],
        'UpdateAccepted' => [
            'workflow_command_id',
            'update_id',
            'workflow_instance_id',
            'workflow_run_id',
            'update_name',
            'arguments',
        ],
        'UpdateApplied' => [
            'workflow_command_id',
            'update_id',
            'workflow_instance_id',
            'workflow_run_id',
            'update_name',
            'arguments',
            'sequence',
        ],
        'UpdateCompleted' => [
            'workflow_command_id',
            'update_id',
            'workflow_instance_id',
            'workflow_run_id',
            'update_name',
            'sequence',
            'result',
        ],
        'ConditionWaitOpened' => [
            'condition_wait_id',
            'condition_key',
            'condition_definition_fingerprint',
            'sequence',
            'timeout_seconds',
        ],
        'SideEffectRecorded' => ['sequence', 'result'],
        'VersionMarkerRecorded' => ['sequence', 'change_id', 'version', 'min_supported', 'max_supported'],
        'ChildWorkflowScheduled' => [
            'sequence',
            'workflow_link_id',
            'child_call_id',
            'child_workflow_instance_id',
            'child_workflow_run_id',
            'child_workflow_class',
            'child_workflow_type',
            'parent_close_policy',
            'retry_policy',
            'timeout_policy',
        ],
        'ChildRunStarted' => [
            'sequence',
            'workflow_link_id',
            'child_call_id',
            'child_workflow_instance_id',
            'child_workflow_run_id',
            'child_workflow_class',
            'child_workflow_type',
            'child_run_number',
            'retry_policy',
            'timeout_policy',
            'execution_timeout_seconds',
            'run_timeout_seconds',
            'execution_deadline_at',
            'run_deadline_at',
        ],
        'ChildRunCompleted' => [
            'sequence',
            'workflow_link_id',
            'child_call_id',
            'child_workflow_instance_id',
            'child_workflow_run_id',
            'child_workflow_class',
            'child_workflow_type',
            'child_run_number',
            'child_status',
            'closed_reason',
            'closed_at',
            'output',
            'parallel_group_path',
        ],
    ];

    /**
     * @var array<string, array{file: string, marker: string, keys: list<string>}>
     */
    private const REPRESENTATIVE_EMIT_SITES = [
        'WorkflowStarted' => [
            'file' => 'src/V2/WorkflowStub.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted',
            'keys' => [
                'workflow_class',
                'workflow_type',
                'workflow_instance_id',
                'workflow_run_id',
                'workflow_command_id',
                'business_key',
                'visibility_labels',
                'memo',
                'search_attributes',
                'execution_timeout_seconds',
                'run_timeout_seconds',
                'execution_deadline_at',
                'run_deadline_at',
                'workflow_definition_fingerprint',
                'declared_queries',
                'declared_query_contracts',
                'declared_signals',
                'declared_signal_contracts',
                'declared_updates',
                'declared_update_contracts',
                'declared_entry_method',
                'declared_entry_mode',
                'declared_entry_declaring_class',
            ],
        ],
        'ActivityScheduled' => [
            'file' => 'src/V2/Support/DefaultWorkflowTaskBridge.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::ActivityScheduled',
            'keys' => self::DOCUMENTED_KEYS['ActivityScheduled'],
        ],
        'ActivityCompleted' => [
            'file' => 'src/V2/Support/ActivityOutcomeRecorder.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::ActivityCompleted',
            'keys' => [
                'activity_execution_id',
                'activity_attempt_id',
                'activity_class',
                'activity_type',
                'sequence',
                'attempt_number',
                'result',
                'payload_codec',
                'activity',
            ],
        ],
        'TimerScheduled' => [
            'file' => 'src/V2/Support/DefaultWorkflowTaskBridge.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::TimerScheduled',
            'keys' => ['timer_id', 'sequence', 'delay_seconds', 'fire_at'],
        ],
        'TimerFired' => [
            'file' => 'src/V2/Support/WorkflowExecutor.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::TimerFired',
            'keys' => ['timer_id', 'sequence', 'delay_seconds', 'fired_at'],
        ],
        'SignalReceived' => [
            'file' => 'src/V2/WorkflowStub.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::SignalReceived',
            'keys' => self::DOCUMENTED_KEYS['SignalReceived'],
        ],
        'SignalApplied' => [
            'file' => 'src/V2/Support/WorkflowExecutor.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::SignalApplied',
            'keys' => self::DOCUMENTED_KEYS['SignalApplied'],
        ],
        'UpdateAccepted' => [
            'file' => 'src/V2/WorkflowStub.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::UpdateAccepted',
            'keys' => self::DOCUMENTED_KEYS['UpdateAccepted'],
        ],
        'UpdateApplied' => [
            'file' => 'src/V2/Support/DefaultWorkflowTaskBridge.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::UpdateApplied',
            'keys' => self::DOCUMENTED_KEYS['UpdateApplied'],
        ],
        'UpdateCompleted' => [
            'file' => 'src/V2/Support/DefaultWorkflowTaskBridge.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::UpdateCompleted',
            'keys' => self::DOCUMENTED_KEYS['UpdateCompleted'],
        ],
        'ConditionWaitOpened' => [
            'file' => 'src/V2/Support/DefaultWorkflowTaskBridge.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::ConditionWaitOpened',
            'keys' => self::DOCUMENTED_KEYS['ConditionWaitOpened'],
        ],
        'SideEffectRecorded' => [
            'file' => 'src/V2/Support/DefaultWorkflowTaskBridge.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::SideEffectRecorded',
            'keys' => self::DOCUMENTED_KEYS['SideEffectRecorded'],
        ],
        'VersionMarkerRecorded' => [
            'file' => 'src/V2/Support/DefaultWorkflowTaskBridge.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::VersionMarkerRecorded',
            'keys' => self::DOCUMENTED_KEYS['VersionMarkerRecorded'],
        ],
        'ChildWorkflowScheduled' => [
            'file' => 'src/V2/Support/DefaultWorkflowTaskBridge.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::ChildWorkflowScheduled',
            'keys' => self::DOCUMENTED_KEYS['ChildWorkflowScheduled'],
        ],
        'ChildRunStarted' => [
            'file' => 'src/V2/Support/DefaultWorkflowTaskBridge.php',
            'marker' => 'WorkflowHistoryEvent::record($run, HistoryEventType::ChildRunStarted',
            'keys' => self::DOCUMENTED_KEYS['ChildRunStarted'],
        ],
    ];

    public function testReplayCriticalEventsAreDocumentedWithFrozenKeysAndConsumers(): void
    {
        $document = $this->fileContents('docs/api-stability.md');

        foreach (self::DOCUMENTED_KEYS as $eventType => $keys) {
            $row = $this->documentationRow($document, $eventType);

            foreach ($keys as $key) {
                $this->assertStringContainsString(
                    sprintf('`%s`', $key),
                    $row,
                    sprintf('%s is missing documented frozen key "%s".', $eventType, $key),
                );
            }

            $this->assertMatchesRegularExpression(
                '/\| `[^`]+` \| [^|]+ \| [^|]*[A-Za-z][^|]* \|/',
                $row,
                sprintf('%s must document at least one replay or projection consumer.', $eventType),
            );
        }
    }

    public function testEveryHistoryEventTypeHasADocumentedWireFormatRow(): void
    {
        $document = $this->fileContents('docs/api-stability.md');

        foreach (HistoryEventType::cases() as $case) {
            $row = $this->documentationRow($document, $case->value);

            $this->assertMatchesRegularExpression(
                '/^\| `[^`]+` \| [^|]*`[a-z_]+`[^|]* \| [^|]*[A-Za-z][^|]* \|$/',
                $row,
                sprintf(
                    '%s must document frozen payload keys and at least one replay/projection consumer.',
                    $case->value,
                ),
            );
        }
    }

    public function testRuntimePayloadContractMatchesDocumentedWireFormatRows(): void
    {
        $documented = $this->documentedWireFormatKeys($this->fileContents('docs/api-stability.md'));
        $contract = HistoryEventPayloadContract::payloadKeys();

        ksort($documented);
        ksort($contract);

        $this->assertSame(
            $documented,
            $contract,
            'HistoryEventPayloadContract must match docs/api-stability.md so producer guards track the public wire contract.',
        );
    }

    public function testRuntimePayloadContractRejectsUndocumentedProducerKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('WorkflowCompleted history payload contains undocumented key(s): surprise');

        HistoryEventPayloadContract::assertKnownPayloadKeys(HistoryEventType::WorkflowCompleted, [
            'output' => 'ok',
            'surprise' => true,
        ]);
    }

    public function testRepresentativePhpEmitSitesStillUseDocumentedKeySets(): void
    {
        foreach (self::REPRESENTATIVE_EMIT_SITES as $eventType => $site) {
            $keys = $this->payloadKeysAfterMarker($site['file'], $site['marker']);
            $expected = $site['keys'];

            sort($keys);
            sort($expected);

            $this->assertSame(
                $expected,
                $keys,
                sprintf(
                    '%s payload keys in %s drifted from docs/api-stability.md. '
                    . 'Changing these keys is a history wire-format break.',
                    $eventType,
                    $site['file'],
                ),
            );
        }
    }

    private function documentationRow(string $document, string $eventType): string
    {
        $pattern = sprintf('/^\| `%s` \| .*$/m', preg_quote($eventType, '/'));

        $this->assertSame(
            1,
            preg_match($pattern, $document, $matches),
            sprintf('Missing docs/api-stability.md history-event row for %s.', $eventType),
        );

        return $matches[0];
    }

    /**
     * @return array<string, list<string>>
     */
    private function documentedWireFormatKeys(string $document): array
    {
        $section = strstr($document, 'The key list is a wire-format list', true);

        $this->assertIsString($section, 'Could not find the end of the frozen history-event table.');
        preg_match_all('/^\| `([^`]+)` \| ([^|]+) \|/m', $section, $matches, PREG_SET_ORDER);

        $keys = [];
        foreach ($matches as $match) {
            preg_match_all('/`([^`]+)`/', $match[2], $keyMatches);
            $keys[$match[1]] = $keyMatches[1];
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function payloadKeysAfterMarker(string $relativePath, string $marker): array
    {
        $source = $this->fileContents($relativePath);
        $markerOffset = strpos($source, $marker);

        $this->assertNotFalse($markerOffset, sprintf('Could not find marker "%s" in %s.', $marker, $relativePath));

        $payloadStart = strpos($source, '[', $markerOffset);
        $this->assertNotFalse(
            $payloadStart,
            sprintf('Could not find payload array after marker "%s" in %s.', $marker, $relativePath),
        );

        $payload = $this->bracketedPhpArray($source, $payloadStart);
        preg_match_all("/'([a-z_]+)'\\s*=>/", $payload, $matches);

        return array_values(array_unique($matches[1]));
    }

    private function bracketedPhpArray(string $source, int $start): string
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }

                if ($char === "'") {
                    $inString = false;
                }

                continue;
            }

            if ($char === "'") {
                $inString = true;

                continue;
            }

            if ($char === '[') {
                $depth++;

                continue;
            }

            if ($char === ']') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }

        $this->fail('Could not parse PHP payload array.');
    }

    private function fileContents(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);

        $this->assertIsString($contents, sprintf('Could not read %s.', $relativePath));

        return $contents;
    }
}
