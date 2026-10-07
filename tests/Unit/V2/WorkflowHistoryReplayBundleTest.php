<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\Fixtures\V2\TestQuerySignalPayloadWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\SignalStatus;
use Workflow\V2\Exceptions\WorkflowOutputCodecUnavailableException;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\WorkflowReplayer;

final class WorkflowHistoryReplayBundleTest extends TestCase
{
    #[DataProvider('signalFields')]
    public function testSignalReconstructionPreservesDeliveryIdentityArgumentsAndRejection(bool $exportNames): void
    {
        $bundle = $this->bundle();
        $bundle['workflow']['workflow_class'] = TestQuerySignalPayloadWorkflow::class;
        $bundle['workflow']['workflow_type'] = 'query-signal-payload';
        $bundle['payloads']['arguments']['data'] = Serializer::serializeWithCodec('avro', []);
        $name = $exportNames ? 'name' : 'signal_name';
        $status = $exportNames ? 'source_status' : 'status';
        $arguments = Serializer::serializeWithCodec('avro', ['Taylor', true]);
        $bundle['signals'] = [
            [
                'id' => 'signal-rejected',
                'command_id' => 'command-two',
                'command_sequence' => 2,
                $name => 'unknown-signal',
                $status => SignalStatus::Rejected->value,
                'outcome' => CommandOutcome::RejectedUnknownSignal->value,
                'rejection_reason' => 'unknown_signal',
                'validation_errors' => [
                    'name' => ['Signal is not declared.'],
                ],
                'received_at' => '2026-10-07T12:00:02.000000Z',
                'rejected_at' => '2026-10-07T12:00:02.100000Z',
            ],
            [
                'id' => 'signal-applied',
                'command_id' => 'command-one',
                'command_sequence' => 1,
                'workflow_sequence' => 3,
                $name => 'approval',
                'signal_wait_id' => 'approval-wait',
                'target_scope' => 'run',
                'requested_run_id' => 'export-run',
                'resolved_run_id' => 'export-run',
                $status => SignalStatus::Applied->value,
                'outcome' => CommandOutcome::SignalReceived->value,
                'payload_codec' => 'avro',
                'arguments' => $arguments,
                'received_at' => '2026-10-07T12:00:01.000000Z',
                'applied_at' => '2026-10-07T12:00:01.100000Z',
            ],
        ];
        $run = $this->reconstruct($bundle);
        $this->assertSame(['signal-applied', 'signal-rejected'], $run->signals->pluck('id')->all());
        $applied = $run->signals[0];
        $rejected = $run->signals[1];

        $this->assertSame($run, $applied->run);
        $this->assertSame('export-instance', $applied->workflow_instance_id);
        $this->assertSame('export-run', $applied->workflow_run_id);
        $this->assertSame('command-one', $applied->workflow_command_id);
        $this->assertSame(1, $applied->command_sequence);
        $this->assertSame(3, $applied->workflow_sequence);
        $this->assertSame('approval', $applied->signal_name);
        $this->assertSame('approval-wait', $applied->signal_wait_id);
        $this->assertSame('run', $applied->target_scope);
        $this->assertSame('export-run', $applied->requested_workflow_run_id);
        $this->assertSame('export-run', $applied->resolved_workflow_run_id);
        $this->assertSame(SignalStatus::Applied, $applied->status);
        $this->assertSame(CommandOutcome::SignalReceived, $applied->outcome);
        $this->assertSame(
            ['Taylor', true],
            Serializer::unserializeWithCodec($applied->payload_codec, $applied->arguments)
        );
        $this->assertSame('2026-10-07T12:00:01.100000Z', $applied->applied_at->toJSON());
        $this->assertNull($applied->rejected_at);
        $this->assertSame(SignalStatus::Rejected, $rejected->status);
        $this->assertSame(CommandOutcome::RejectedUnknownSignal, $rejected->outcome);
        $this->assertSame('unknown_signal', $rejected->rejection_reason);
        $this->assertSame([
            'name' => ['Signal is not declared.'],
        ], $rejected->normalizedValidationErrors());
        $this->assertSame('2026-10-07T12:00:02.100000Z', $rejected->rejected_at->toJSON());
        $this->assertNull($rejected->applied_at);
    }

    public static function signalFields(): array
    {
        return [
            'export field names' => [true],
            'persisted field aliases' => [false],
        ];
    }

    public function testFailureReconstructionRetainsEachSourceCategoryAndDiagnostic(): void
    {
        $bundle = $this->bundle();
        $bundle['failures'] = [
            [
                'id' => 'failure-activity',
                'source_kind' => 'activity_execution',
                'source_id' => 'activity-one',
                'failure_category' => FailureCategory::Application->value,
                'exception_type' => 'payment_rejected',
                'exception_class' => DomainException::class,
                'message' => 'Payment was declined.',
                'created_at' => '2026-10-07T12:00:01.100000Z',
            ],
            [
                'id' => 'failure-task',
                'source_kind' => 'workflow_task',
                'source_id' => 'task-two',
                'failure_category' => FailureCategory::TaskFailure->value,
                'exception_type' => 'invalid_command',
                'exception_class' => LogicException::class,
                'message' => 'The command is invalid.',
                'created_at' => '2026-10-07T12:00:02.100000Z',
            ],
        ];
        $run = $this->reconstruct($bundle);
        $this->assertSame(['failure-activity', 'failure-task'], $run->failures->pluck('id')->all());
        foreach ($bundle['failures'] as $index => $expected) {
            $failure = $run->failures[$index];
            $this->assertSame($run, $failure->run);
            $this->assertSame('export-run', $failure->workflow_run_id);
            foreach (['source_kind', 'source_id', 'exception_type', 'exception_class', 'message'] as $field) {
                $this->assertSame($expected[$field], $failure->{$field});
            }
            $this->assertSame(FailureCategory::from($expected['failure_category']), $failure->failure_category);
            $this->assertSame($expected['created_at'], $failure->created_at->toJSON());
        }
    }

    #[DataProvider('payloadRepresentations')]
    public function testInlinePayloadRepresentationsPreserveRunArgumentsAndOutput(bool $wrapped): void
    {
        $bundle = $this->bundle();
        $arguments = Serializer::serializeWithCodec('avro', ['Taylor']);
        $output = Serializer::serializeWithCodec('avro', 'approved');
        $bundle['workflow']['status'] = RunStatus::Completed->value;
        $bundle['payloads']['arguments']['data'] = $wrapped ? [
            'codec' => 'avro',
            'blob' => $arguments,
        ] : $arguments;
        $bundle['payloads']['output'] = [
            'available' => true,
            'codec' => 'avro',
            'data' => $wrapped ? [
                'codec' => 'avro',
                'blob' => $output,
            ] : $output,
        ];
        $run = $this->reconstruct($bundle);

        $this->assertSame(['Taylor'], Serializer::unserializeWithCodec($run->payload_codec, $run->arguments));
        $this->assertSame('approved', Serializer::unserializeWithCodec($run->outputPayloadCodec(), $run->output));
        $this->assertSame($arguments, $run->arguments);
        $this->assertSame($output, $run->output);
    }

    public static function payloadRepresentations(): array
    {
        return [
            'serialized payload' => [false],
            'inline Avro envelope' => [true],
        ];
    }

    public function testExternalOutputRetainsItsEnvelopeCodecWithoutFetchingBytes(): void
    {
        $bundle = $this->bundle();
        $bundle['workflow']['status'] = RunStatus::Completed->value;
        $envelope = [
            'codec' => 'avro',
            'external_storage' => [
                'schema' => ExternalPayloadReference::SCHEMA,
                'uri' => 'local://replay-bundle/output',
                'sha256' => hash('sha256', 'exported-output'),
                'size_bytes' => strlen('exported-output'),
                'codec' => 'avro',
            ],
        ];
        $bundle['payloads']['output'] = [
            'available' => true,
            'data' => $envelope,
        ];
        $run = $this->reconstruct($bundle);

        $this->assertSame('avro', $run->output_payload_codec);
        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $run->output);
        $this->assertEquals($envelope, ExternalPayloads::storedEnvelope($run->output));
    }

    #[DataProvider('completionEvidence')]
    public function testInlineOutputRequiresItsOwnCodecEvidence(bool $completionRecorded): void
    {
        $bundle = $this->bundle();
        $bundle['workflow']['status'] = RunStatus::Completed->value;
        $bundle['payloads']['output'] = [
            'available' => true,
            'data' => Serializer::serializeWithCodec('avro', 'approved'),
        ];
        $bundle['history_events'] = [[
            'id' => 'history-start',
            'sequence' => 1,
            'type' => HistoryEventType::WorkflowStarted->value,
            'payload' => [],
        ]];
        if ($completionRecorded) {
            $bundle['history_events'][] = [
                'id' => 'history-completed',
                'sequence' => 2,
                'type' => HistoryEventType::WorkflowCompleted->value,
                'payload' => [
                    'payload_codec' => 'avro',
                ],
            ];
        }
        $run = $this->reconstruct($bundle);

        $this->assertSame($completionRecorded ? 'avro' : null, $run->output_payload_codec);
        $this->assertSame($bundle['payloads']['output']['data'], $run->output);
        if (! $completionRecorded) {
            // The argument codec cannot establish how terminal output was encoded.
            $this->expectException(WorkflowOutputCodecUnavailableException::class);
            $this->expectExceptionMessage('Workflow output codec is unavailable for run [export-run]');
            $run->outputPayloadCodec();

            return;
        }
        $this->assertSame('avro', $run->outputPayloadCodec());
        $this->assertSame('approved', Serializer::unserializeWithCodec($run->outputPayloadCodec(), $run->output));
    }

    public static function completionEvidence(): array
    {
        return [
            'completion present' => [true],
            'partial history' => [false],
        ];
    }

    #[DataProvider('unsupportedVersions')]
    public function testIncompatibleStructuralVersionsReportTheSupportedVersion(mixed $version): void
    {
        $bundle = $this->bundle();
        $bundle['schema_version'] = $version;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Workflow history replay supports durable-workflow.v2.history-export schema_version 2.'
        );
        (new WorkflowReplayer())->runFromHistoryExport($bundle);
    }

    public static function unsupportedVersions(): array
    {
        return [
            'older shape' => [1],
            'future shape' => [3],
            'string version' => ['2'],
        ];
    }

    #[DataProvider('invalidIdentities')]
    public function testMissingIdentitiesIdentifyTheBrokenExportSection(
        string $section,
        string $field,
        mixed $value,
        string $path,
    ): void {
        $bundle = $this->bundle();
        if ($section === 'workflow') {
            $bundle[$section][$field] = $value;
        } else {
            $bundle[$section] = [[
                'id' => 'record-one',
                'type' => HistoryEventType::WorkflowStarted->value,
            ]];
            $bundle[$section][0][$field] = $value;
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('History export field [' . $path . '] must be a non-empty string.');
        (new WorkflowReplayer())->runFromHistoryExport($bundle);
    }

    public static function invalidIdentities(): array
    {
        return [
            'run identity' => ['workflow', 'run_id', '', 'run_id'],
            'workflow definition' => ['workflow', 'workflow_class', false, 'workflow_class'],
            'history event type' => ['history_events', 'type', null, 'history_events[].type'],
            'command identity' => ['commands', 'id', 42, 'commands[].id'],
            'signal identity' => ['signals', 'id', '', 'signals[].id'],
            'failure identity' => ['failures', 'id', null, 'failures[].id'],
        ];
    }

    #[DataProvider('unavailablePayloadData')]
    public function testAvailablePayloadsRequireUsableBytesOrAnExternalReference(string $field, mixed $data): void
    {
        $bundle = $this->bundle();
        $bundle['payloads'][$field] = [
            'available' => true,
            'data' => $data,
        ];
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'History export field [payloads.' . $field . '.data] must be a non-empty payload string or external storage envelope.',
        );
        (new WorkflowReplayer())->runFromHistoryExport($bundle);
    }

    public static function unavailablePayloadData(): array
    {
        $cases = [];
        foreach (['arguments', 'output'] as $field) {
            foreach ([
                'absent' => null,
                'empty' => '',
                'non-string' => false,
                'envelope without bytes' => [
                    'codec' => 'avro',
                ],
            ] as $name => $value) {
                $cases[$field . ': ' . $name] = [$field, $value];
            }
        }

        return $cases;
    }

    private function bundle(): array
    {
        return [
            'schema' => HistoryExport::SCHEMA,
            'schema_version' => HistoryExport::SCHEMA_VERSION,
            'workflow' => [
                'instance_id' => 'export-instance',
                'run_id' => 'export-run',
                'workflow_type' => 'greeting',
                'workflow_class' => TestGreetingWorkflow::class,
                'status' => RunStatus::Waiting->value,
            ],
            'payloads' => [
                'codec' => 'avro',
                'arguments' => [
                    'available' => true,
                    'data' => Serializer::serializeWithCodec('avro', ['Taylor']),
                ],
                'output' => [
                    'available' => false,
                ],
            ],
        ];
    }

    private function reconstruct(array $bundle): WorkflowRun
    {
        $before = $bundle;
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $replayer = new WorkflowReplayer();
            $run = $replayer->runFromHistoryExport($bundle);
            $second = $replayer->runFromHistoryExport($bundle);
            $this->assertSame($this->attributes($run), $this->attributes($second));
            foreach ([
                'historyEvents',
                'commands',
                'signals',
                'activityExecutions',
                'timers',
                'failures',
            ] as $relation) {
                $this->assertSame(
                    $run->{$relation}->map(fn (Model $record): array => $this->attributes($record))
                        ->all(),
                    $second->{$relation}->map(fn (Model $record): array => $this->attributes($record))
                        ->all(),
                );
            }
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertSame($before, $bundle);
        $this->assertSame($run, $run->instance->currentRun);

        return $run;
    }

    private function attributes(Model $record): array
    {
        // Each reconstruction creates new timestamp objects; compare their exact
        // represented instants while retaining strict primitive value types.
        return array_map(
            static fn (mixed $value): mixed => $value instanceof CarbonInterface ? $value->toJSON() : $value,
            $record->getRawOriginal(),
        );
    }
}
