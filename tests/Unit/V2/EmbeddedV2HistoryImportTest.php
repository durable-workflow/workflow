<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\ActivityStatus;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Enums\TimerStatus;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowMemo;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowSearchAttribute;
use Workflow\V2\Models\WorkflowSignal;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\Models\WorkflowTimer;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\EmbeddedV2HistoryImport;
use Workflow\V2\Support\EmbeddedV2ImportContract;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\RunDetailView;
use Workflow\V2\Support\RunSignalView;
use Workflow\V2\Support\RunUpdateView;

final class EmbeddedV2HistoryImportTest extends TestCase
{
    #[DataProvider('invalidMessageCommands')]
    public function testImportRejectsMessageCommandReferencesOutsideItsBundle(
        string $kind,
        string $case,
        bool $dryRun
    ): void {
        [$bundle, $messageId] = $this->messageBundle($kind);
        $this->clearWorkflowState();
        $command = null;
        $foreignBefore = null;
        if (in_array($case, ['foreign-namespace', 'same-namespace-other-run', 'null-namespace'], true)) {
            $namespace = match ($case) {
                'same-namespace-other-run' => 'tenant-a',
                'null-namespace' => null,
                default => 'tenant-b',
            };
            $command = $this->otherMessageCommand($kind, $namespace);
            $foreignBefore = $command->getAttributes();
            $bundle[$kind === 'signal' ? 'signals' : 'updates'][0]['command_id'] = $command->id;
        } elseif ($case === 'missing-command') {
            $bundle['commands'] = [];
        } elseif ($case === 'wrong-kind') {
            $bundle['commands'][0]['type'] = $kind === 'signal' ? 'update' : 'signal';
        } elseif ($case === 'invalid-command-status') {
            $bundle['commands'][0]['status'] = 'unknown';
        } elseif ($case === 'padded-command-kind') {
            $bundle['commands'][0]['type'] = ' ' . $kind . ' ';
        } elseif ($case === 'padded-reference') {
            $bundle[$kind === 'signal' ? 'signals' : 'updates'][0]['command_id'] = ' ' . $bundle['commands'][0]['id'] . ' ';
        } else {
            unset($bundle[$kind === 'signal' ? 'signals' : 'updates'][0]['command_id']);
        }
        $bundle = $this->resealBundle($bundle);
        $before = $this->workflowStateSnapshot();

        $report = EmbeddedV2HistoryImport::import($bundle, [
            'dry_run' => $dryRun,
        ]);
        if (! $dryRun && $report['status'] === 'imported' && $command instanceof WorkflowCommand) {
            // The counterfactual must reach ordinary worker application, where
            // an unowned command ID used to change another run's command.
            $this->applyImportedMessage($bundle, $kind, $messageId);
        }

        if ($command instanceof WorkflowCommand) {
            $this->assertSame($foreignBefore, $command->fresh()->getAttributes());
        }
        $this->assertSame('rejected', $report['status']);
        $this->assertContains(
            'references.message_command_invalid',
            array_column($report['eligibility']['errors'], 'rule')
        );
        $this->assertSame(0, array_sum($report['rows']));
        $this->assertSame($before, $this->workflowStateSnapshot());
    }

    public static function invalidMessageCommands(): iterable
    {
        foreach (['signal', 'update'] as $kind) {
            foreach (['foreign-namespace', 'same-namespace-other-run', 'null-namespace', 'missing-command',
                'wrong-kind', 'invalid-command-status', 'missing-command-id', 'padded-command-kind', 'padded-reference'] as $case) {
                foreach ([false, true] as $dryRun) {
                    yield $kind . '/' . $case . ($dryRun ? '/preview' : '/write') => [$kind, $case, $dryRun];
                }
            }
        }
    }

    #[DataProvider('messageKinds')]
    public function testOwnedImportedMessageCommandsStillApplyOnTheImportedRun(string $kind): void
    {
        [$bundle, $messageId] = $this->messageBundle($kind);
        $this->clearWorkflowState();
        $other = $this->otherMessageCommand($kind, 'tenant-b');
        $before = $other->getAttributes();

        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('imported', $report['status']);
        $this->applyImportedMessage($bundle, $kind, $messageId);
        $command = WorkflowCommand::query()->findOrFail($bundle['commands'][0]['id']);
        $this->assertSame($bundle['workflow']['run_id'], $command->workflow_run_id);
        $this->assertSame($bundle['workflow']['instance_id'], $command->workflow_instance_id);
        $this->assertNotNull($command->applied_at);
        $this->assertSame($before, $other->fresh()->getAttributes());
        $this->assertSame('already_imported', EmbeddedV2HistoryImport::import($bundle)['status']);
    }

    public static function messageKinds(): iterable
    {
        yield 'signal' => ['signal'];
        yield 'update' => ['update'];
    }

    #[DataProvider('namespaceConflicts')]
    public function testItNeverTreatsAnotherNamespacesRunAsAnIdempotentImport(
        ?string $target,
        ?string $existing,
        bool $dryRun
    ): void {
        $bundle = $this->runningBundleWithOpenWork();
        $bundle['workflow']['namespace'] = $target;
        $bundle = $this->resealBundle($bundle);
        $this->clearWorkflowState();
        $this->assertSame('imported', EmbeddedV2HistoryImport::import($bundle)['status']);
        WorkflowRun::query()->update([
            'namespace' => $existing,
        ]);
        WorkflowInstance::query()->update([
            'namespace' => $existing,
        ]);
        $before = $this->workflowStateSnapshot();

        $report = EmbeddedV2HistoryImport::import($bundle, [
            'dry_run' => $dryRun,
        ]);

        $this->assertSame('rejected', $report['status']);
        $this->assertContains('target.run_id_conflict', array_column($report['eligibility']['errors'], 'rule'));
        $this->assertSame(0, array_sum($report['rows']));
        $this->assertSame($before, $this->workflowStateSnapshot());
    }

    #[DataProvider('namespaceConflicts')]
    public function testItNeverAttachesAnImportedRunToAnotherNamespacesInstance(
        ?string $target,
        ?string $existing,
        bool $dryRun
    ): void {
        $bundle = $this->runningBundleWithOpenWork();
        $bundle['workflow']['namespace'] = $target;
        $bundle = $this->resealBundle($bundle);
        $this->clearWorkflowState();
        WorkflowInstance::query()->create([
            'id' => $bundle['workflow']['instance_id'],
            'namespace' => $existing,
            'workflow_class' => $bundle['workflow']['workflow_class'],
            'workflow_type' => $bundle['workflow']['workflow_type'],
            'run_count' => 7,
            'current_run_id' => (string) Str::ulid(),
        ]);
        $before = $this->workflowStateSnapshot();

        $report = EmbeddedV2HistoryImport::import($bundle, [
            'dry_run' => $dryRun,
        ]);

        $this->assertSame('rejected', $report['status']);
        $this->assertContains(
            'target.instance_namespace_conflict',
            array_column($report['eligibility']['errors'], 'rule')
        );
        $this->assertSame(0, array_sum($report['rows']));
        $this->assertSame($before, $this->workflowStateSnapshot());
    }

    public static function namespaceConflicts(): iterable
    {
        foreach ([
            'named' => ['tenant-a', 'tenant-b'],
            'null-target' => [null, 'tenant-b'],
            'null-existing' => ['tenant-a', null],
        ] as $label => [$target, $existing]) {
            foreach ([false, true] as $dryRun) {
                yield $label . ($dryRun ? '-preview' : '-write') => [$target, $existing, $dryRun];
            }
        }
    }

    public function testTheTransactionalInstanceLookupRechecksNamespaceAfterPreflight(): void
    {
        $bundle = $this->runningBundleWithOpenWork();
        $bundle['workflow']['namespace'] = 'tenant-a';
        $bundle = $this->resealBundle($bundle);
        $this->clearWorkflowState();
        WorkflowInstance::query()->create([
            'id' => $bundle['workflow']['instance_id'],
            'namespace' => 'tenant-a',
            'workflow_class' => $bundle['workflow']['workflow_class'],
            'workflow_type' => $bundle['workflow']['workflow_type'],
            'run_count' => 7,
        ]);
        $afterTransfer = null;
        $transferred = false;
        DB::listen(function ($query) use (&$transferred, &$afterTransfer, $bundle): void {
            if ($transferred || DB::transactionLevel() !== 0
                || ! str_contains($query->sql, 'select') || ! str_contains($query->sql, 'workflow_instances')) {
                return;
            }
            // The result of the preflight read is already materialized. A
            // concurrent transfer must not authorize attaching to its old scope.
            $transferred = true;
            WorkflowInstance::query()->whereKey($bundle['workflow']['instance_id'])->update([
                'namespace' => 'tenant-b',
            ]);
            $afterTransfer = $this->workflowStateSnapshot();
        });

        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertTrue($transferred);
        $this->assertSame('rejected', $report['status']);
        $this->assertContains('target.write_failed', array_column($report['eligibility']['errors'], 'rule'));
        $this->assertSame(0, array_sum($report['rows']));
        $this->assertSame($afterTransfer, $this->workflowStateSnapshot());
    }

    public function testItCanImportANewRunIntoAnExistingInstanceInTheSameNamespace(): void
    {
        $bundle = $this->runningBundleWithOpenWork();
        $bundle['workflow']['namespace'] = 'tenant-a';
        $bundle = $this->resealBundle($bundle);
        $this->clearWorkflowState();
        WorkflowInstance::query()->create([
            'id' => $bundle['workflow']['instance_id'],
            'namespace' => 'tenant-a',
            'workflow_class' => $bundle['workflow']['workflow_class'],
            'workflow_type' => $bundle['workflow']['workflow_type'],
            'run_count' => 7,
        ]);

        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('imported', $report['status']);
        $this->assertSame(0, $report['rows']['workflow_instances']);
        $this->assertSame(1, $report['rows']['workflow_runs']);
        $instance = WorkflowInstance::query()->findOrFail($bundle['workflow']['instance_id']);
        $this->assertSame('tenant-a', $instance->namespace);
        $this->assertSame(7, (int) $instance->run_count);
        $this->assertSame($bundle['workflow']['run_id'], $instance->current_run_id);
    }

    public function testContractPublishesImportRulesAndAuditMarkers(): void
    {
        $manifest = EmbeddedV2ImportContract::manifest();

        $this->assertSame('durable-workflow.v2.embedded-v2-import.contract', $manifest['schema']);
        $this->assertSame(2, $manifest['version']);
        $this->assertSame(HistoryExport::SCHEMA, $manifest['history_export']['schema']);
        $this->assertSame(2, $manifest['history_export']['schema_version']);
        $this->assertSame('workflow:v2:history-import {bundle}', $manifest['import']['command']);
        $this->assertSame(['embedded'], $manifest['eligibility']['supported_source_runtimes']);
        $this->assertSame('out_of_scope', $manifest['eligibility']['v1_history']);
        $this->assertContains('import_source', $manifest['visibility_and_audit']['workflow_run_columns']);
        $this->assertSame('embedded_v2_import', $manifest['visibility_and_audit']['summary_engine_source']);
    }

    public function testItImportsAnEligibleRunningEmbeddedRunAndRebuildsServerState(): void
    {
        Carbon::setTestNow('2026-05-05 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $bundle = $this->runningBundleWithOpenWork();
        $runId = $bundle['workflow']['run_id'];
        $this->clearWorkflowState();

        $report = EmbeddedV2HistoryImport::import($bundle, [
            'namespace' => 'server-production',
            'import_id' => 'operator-import-001',
        ]);

        $this->assertSame('imported', $report['status']);
        $this->assertSame('operator-import-001', $report['import_id']);
        $this->assertSame(1, $report['rows']['workflow_runs']);
        $this->assertSame(2, $report['rows']['workflow_tasks']);
        $this->assertSame(1, $report['rows']['activity_executions']);
        $this->assertSame(1, $report['rows']['workflow_run_timers']);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        $this->assertSame('server-production', $run->namespace);
        $this->assertSame('embedded_v2', $run->import_source);
        $this->assertSame('operator-import-001', $run->import_id);
        $this->assertSame($bundle['dedupe_key'], $run->import_dedupe_key);
        $this->assertNotNull($run->imported_at);

        /** @var WorkflowRunSummary $summary */
        $summary = WorkflowRunSummary::query()->findOrFail($runId);
        $this->assertSame('embedded_v2_import', $summary->engine_source);
        $this->assertSame('server-production', $summary->namespace);
        $this->assertTrue((bool) $summary->is_current_run);
        $this->assertSame(1, ActivityExecution::query()->where('workflow_run_id', $runId)->count());
        $this->assertSame(1, WorkflowTimer::query()->where('workflow_run_id', $runId)->count());

        $again = EmbeddedV2HistoryImport::import($bundle, [
            'namespace' => 'server-production',
        ]);

        $this->assertSame('already_imported', $again['status']);
    }

    public function testItRejectsLeasedEmbeddedTasksWithoutWritingRows(): void
    {
        $bundle = $this->runningBundleWithOpenWork(TaskStatus::Leased);
        $runId = $bundle['workflow']['run_id'];
        $this->clearWorkflowState();

        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('rejected', $report['status']);
        $this->assertContains('tasks.leased_task_present', array_column($report['eligibility']['errors'], 'rule'));
        $this->assertFalse(WorkflowRun::query()->whereKey($runId)->exists());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count());
    }

    public function testItRejectsStandaloneServerExports(): void
    {
        config([
            'server.topology.shape' => 'standalone_server',
            'server.topology.process_class' => 'server_http_node',
        ]);

        $bundle = $this->runningBundleWithOpenWork();
        $runId = $bundle['workflow']['run_id'];
        $this->assertSame('standalone_server', $bundle['workflow']['source_runtime']);
        $this->clearWorkflowState();

        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('rejected', $report['status']);
        $this->assertContains(
            'workflow.source_runtime_unsupported',
            array_column($report['eligibility']['errors'], 'rule'),
        );
        $this->assertFalse(WorkflowRun::query()->whereKey($runId)->exists());
    }

    public function testItRejectsJsonAsTheTopLevelPayloadCodecWithoutWritingRows(): void
    {
        $bundle = $this->runningBundleWithOpenWork();
        $runId = $bundle['workflow']['run_id'];
        $bundle['payloads']['codec'] = 'json';
        $bundle = $this->resealBundle($bundle);
        $this->clearWorkflowState();

        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('rejected', $report['status']);
        $this->assertContains('payload_codec.unsupported', array_column($report['eligibility']['errors'], 'rule'));
        $this->assertStringContainsString(
            'payloads.codec',
            implode(' ', array_column($report['eligibility']['errors'], 'message')),
        );
        $this->assertFalse(WorkflowRun::query()->whereKey($runId)->exists());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count());
    }

    public function testItRejectsJsonInANestedHistoryPayloadWithoutWritingRows(): void
    {
        $bundle = $this->runningBundleWithOpenWork();
        $runId = $bundle['workflow']['run_id'];
        $bundle['history_events'][] = [
            'id' => (string) Str::ulid(),
            'sequence' => 2,
            'type' => HistoryEventType::ActivityCompleted->value,
            'payload' => [
                'result' => [
                    'codec' => 'json',
                    'blob' => '{"status":"completed"}',
                ],
            ],
            'recorded_at' => now()
                ->toJSON(),
        ];
        $bundle = $this->resealBundle($bundle);
        $this->clearWorkflowState();

        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('rejected', $report['status']);
        $this->assertContains('payload_codec.unsupported', array_column($report['eligibility']['errors'], 'rule'));
        $this->assertStringContainsString(
            'history_events.1.payload.result.codec',
            implode(' ', array_column($report['eligibility']['errors'], 'message')),
        );
        $this->assertFalse(WorkflowRun::query()->whereKey($runId)->exists());
        $this->assertSame(0, WorkflowHistoryEvent::query()->where('workflow_run_id', $runId)->count());
    }

    public function testItRoundTripsCodecLookingMemoAndSearchAttributeData(): void
    {
        $bundle = $this->runningBundleWithOpenWork();
        $runId = $bundle['workflow']['run_id'];

        /** @var WorkflowRun $sourceRun */
        $sourceRun = WorkflowRun::query()->findOrFail($runId);
        $memoValue = [
            'payload_codec' => 'json',
            'details_payload_codec' => 'customer-codec',
            'nested' => [
                'codec' => 'json',
                'blob' => 'user data',
            ],
        ];
        $this->createMemo($sourceRun, 'codec_metadata', $memoValue);
        $this->createMemo($sourceRun, 'payload_codec', 'json');
        $this->createSearchAttribute($sourceRun, 'payload_codec', 'json');

        WorkflowHistoryEvent::record($sourceRun, HistoryEventType::MemoUpserted, [
            'sequence' => 2,
            'entries' => MemoPayload::envelope([
                'codec_metadata' => $memoValue,
                'payload_codec' => 'json',
            ]),
            'merged' => MemoPayload::envelope([
                'codec_metadata' => $memoValue,
                'payload_codec' => 'json',
            ]),
        ]);
        WorkflowHistoryEvent::record($sourceRun, HistoryEventType::SearchAttributesUpserted, [
            'sequence' => 3,
            'attributes' => [
                'payload_codec' => 'json',
            ],
            'merged' => [
                'payload_codec' => 'json',
            ],
        ]);

        $bundle = HistoryExport::forRun($sourceRun->fresh());
        $this->clearWorkflowState();

        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('imported', $report['status']);

        /** @var WorkflowRun $importedRun */
        $importedRun = WorkflowRun::query()->findOrFail($runId);
        $roundTrip = HistoryExport::forRun($importedRun->fresh());

        $this->assertSame($bundle['workflow']['memo'], $roundTrip['workflow']['memo']);
        $this->assertSame($bundle['workflow']['search_attributes'], $roundTrip['workflow']['search_attributes']);

        $memoEvent = collect($roundTrip['history_events'])->firstWhere('type', HistoryEventType::MemoUpserted->value);
        $searchEvent = collect($roundTrip['history_events'])
            ->firstWhere('type', HistoryEventType::SearchAttributesUpserted->value);

        $this->assertIsArray($memoEvent);
        $this->assertIsArray($searchEvent);
        $memoEntries = MemoPayload::decodeEntries($memoEvent['payload']['entries']);
        $this->assertEquals($memoValue, $memoEntries['codec_metadata']);
        $this->assertSame('json', $memoEntries['payload_codec']);
        $this->assertSame('json', $searchEvent['payload']['attributes']['payload_codec']);
    }

    public function testItRejectsJsonAndUnknownCodecsAtEveryPayloadRowDeclaration(): void
    {
        $bundle = $this->runningBundleWithOpenWork();
        $this->clearWorkflowState();

        $mutations = [
            'payloads.codec' => static function (array &$candidate, string $codec): void {
                $candidate['payloads']['codec'] = $codec;
            },
            'payloads.output.codec' => static function (array &$candidate, string $codec): void {
                $candidate['payloads']['output']['codec'] = $codec;
            },
            'history_events.1.payload.payload_codec' => static function (array &$candidate, string $codec): void {
                $candidate['history_events'][] = [
                    'type' => HistoryEventType::ActivityCompleted->value,
                    'payload' => [
                        'payload_codec' => $codec,
                        'result' => 'payload',
                    ],
                ];
            },
            'history_events.1.payload.activity.payload_codec' => static function (
                array &$candidate,
                string $codec,
            ): void {
                $candidate['history_events'][] = [
                    'type' => HistoryEventType::ActivityScheduled->value,
                    'payload' => [
                        'activity' => [
                            'payload_codec' => $codec,
                            'arguments' => 'payload',
                        ],
                    ],
                ];
            },
            'history_events.1.payload.exception.details_payload_codec' => static function (
                array &$candidate,
                string $codec,
            ): void {
                $candidate['history_events'][] = [
                    'type' => HistoryEventType::ActivityFailed->value,
                    'payload' => [
                        'exception' => [
                            'details' => 'payload',
                            'details_payload_codec' => $codec,
                        ],
                    ],
                ];
            },
            'commands.0.payload_codec' => static function (array &$candidate, string $codec): void {
                $candidate['commands'][] = [
                    'payload_codec' => $codec,
                ];
            },
            'signals.0.payload_codec' => static function (array &$candidate, string $codec): void {
                $candidate['signals'][] = [
                    'payload_codec' => $codec,
                ];
            },
            'updates.0.payload_codec' => static function (array &$candidate, string $codec): void {
                $candidate['updates'][] = [
                    'payload_codec' => $codec,
                ];
            },
            'activities.0.payload_codec' => static function (array &$candidate, string $codec): void {
                $candidate['activities'][0]['payload_codec'] = $codec;
            },
            'payload_manifest.entries.0.codec' => static function (array &$candidate, string $codec): void {
                $candidate['payload_manifest']['entries'][0]['codec'] = $codec;
            },
        ];

        foreach (['json', 'customer-codec'] as $codec) {
            foreach ($mutations as $expectedPath => $mutate) {
                $candidate = $bundle;
                $mutate($candidate, $codec);

                $report = EmbeddedV2HistoryImport::import($this->resealBundle($candidate), [
                    'dry_run' => true,
                ]);
                $messages = implode(' ', array_column($report['eligibility']['errors'], 'message'));

                $this->assertSame('rejected', $report['status'], $expectedPath . ' accepted ' . $codec);
                $this->assertContains(
                    'payload_codec.unsupported',
                    array_column($report['eligibility']['errors'], 'rule'),
                    $expectedPath . ' accepted ' . $codec,
                );
                $this->assertStringContainsString($expectedPath, $messages);
            }
        }
    }

    public function testItRejectsUnsupportedCodecsInEveryPayloadEnvelopeShape(): void
    {
        $bundle = $this->runningBundleWithOpenWork();
        $this->clearWorkflowState();

        foreach (['json', 'customer-codec'] as $codec) {
            $inline = $bundle;
            $inline['payloads']['arguments']['data'] = [
                'codec' => $codec,
                'blob' => 'payload',
            ];

            $externalEnvelope = $bundle;
            $externalEnvelope['payloads']['arguments']['data'] = $this->externalStorageEnvelope(
                $codec,
                'unsupported-envelope-' . $codec,
            );

            $externalReference = $bundle;
            $externalReference['payloads']['arguments']['data'] = $this->externalStorageEnvelope(
                CodecRegistry::defaultCodec(),
                'unsupported-reference-' . $codec,
            );
            $externalReference['payloads']['arguments']['data']['external_storage']['codec'] = $codec;

            $cases = [
                'payloads.arguments.data.codec' => $inline,
                'payloads.arguments.data.codec (external)' => $externalEnvelope,
                'payloads.arguments.data.external_storage.codec' => $externalReference,
            ];

            foreach ($cases as $expectedPath => $candidate) {
                $report = EmbeddedV2HistoryImport::import($this->resealBundle($candidate), [
                    'dry_run' => true,
                ]);
                $messages = implode(' ', array_column($report['eligibility']['errors'], 'message'));
                $path = str_replace(' (external)', '', $expectedPath);

                $this->assertSame('rejected', $report['status'], $expectedPath . ' accepted ' . $codec);
                $this->assertContains(
                    'payload_codec.unsupported',
                    array_column($report['eligibility']['errors'], 'rule'),
                    $expectedPath . ' accepted ' . $codec,
                );
                $this->assertStringContainsString($path, $messages);
            }
        }
    }

    public function testItImportsExternalizedActivityPayloadEnvelopesWithoutDroppingReferences(): void
    {
        $bundle = $this->runningBundleWithOpenWork();
        $codec = is_string($bundle['payloads']['codec'] ?? null)
            ? $bundle['payloads']['codec']
            : CodecRegistry::defaultCodec();
        $arguments = $this->externalStorageEnvelope($codec, 'import-activity-arguments');
        $result = $this->externalStorageEnvelope($codec, 'import-activity-result');
        $bundle['activities'][0]['payload_codec'] = $codec;
        $bundle['activities'][0]['arguments'] = $arguments;
        $bundle['activities'][0]['result'] = $result;
        $bundle = $this->resealBundle($bundle);
        $runId = $bundle['workflow']['run_id'];
        $this->clearWorkflowState();

        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('imported', $report['status']);

        /** @var ActivityExecution $activity */
        $activity = ActivityExecution::query()
            ->where('workflow_run_id', $runId)
            ->firstOrFail();

        $this->assertIsString($activity->arguments);
        $this->assertIsString($activity->result);
        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $activity->arguments);
        $this->assertStringStartsWith(ExternalPayloads::STORED_REFERENCE_PREFIX, $activity->result);
        $this->assertSameJsonObject($arguments, ExternalPayloads::storedEnvelope($activity->arguments));
        $this->assertSameJsonObject($result, ExternalPayloads::storedEnvelope($activity->result));

        $export = HistoryExport::forRun(WorkflowRun::query()->findOrFail($runId));

        $this->assertSameJsonObject($arguments, $export['activities'][0]['arguments']);
        $this->assertSameJsonObject($result, $export['activities'][0]['result']);
    }

    public function testItImportsExternalizedRunCommandSignalAndUpdatePayloadEnvelopesWithoutDroppingReferences(): void
    {
        $bundle = $this->runningBundleWithOpenWork();
        $codec = is_string($bundle['payloads']['codec'] ?? null)
            ? $bundle['payloads']['codec']
            : CodecRegistry::defaultCodec();
        $runArguments = $this->externalStorageEnvelope($codec, 'import-run-arguments');
        $commandPayload = $this->externalStorageEnvelope($codec, 'import-command-payload');
        $signalArguments = $this->externalStorageEnvelope($codec, 'import-signal-arguments');
        $updateArguments = $this->externalStorageEnvelope($codec, 'import-update-arguments');
        $updateResult = $this->externalStorageEnvelope($codec, 'import-update-result');
        $runId = $bundle['workflow']['run_id'];
        $commandId = (string) Str::ulid();
        $signalCommandId = (string) Str::ulid();
        $updateCommandId = (string) Str::ulid();
        $now = now()
            ->toJSON();

        $bundle['payloads']['arguments']['data'] = $runArguments;
        $bundle['commands'] = [
            [
                'id' => $commandId,
                'sequence' => 1,
                'type' => 'start',
                'target_scope' => 'instance',
                'payload_codec' => $codec,
                'payload' => $commandPayload,
                'source' => 'embedded',
                'status' => 'accepted',
                'outcome' => 'started_new',
                'accepted_at' => $now,
                'applied_at' => $now,
            ],
            [
                'id' => $signalCommandId,
                'sequence' => 2,
                'type' => 'signal',
                'target_scope' => 'instance',
                'payload_codec' => $codec,
                'payload' => $this->externalStorageEnvelope($codec, 'import-signal-command-payload'),
                'source' => 'embedded',
                'status' => 'accepted',
                'outcome' => 'signal_received',
                'accepted_at' => $now,
                'applied_at' => $now,
            ],
            [
                'id' => $updateCommandId,
                'sequence' => 3,
                'type' => 'update',
                'target_scope' => 'instance',
                'payload_codec' => $codec,
                'payload' => $this->externalStorageEnvelope($codec, 'import-update-command-payload'),
                'source' => 'embedded',
                'status' => 'accepted',
                'outcome' => 'update_completed',
                'accepted_at' => $now,
                'applied_at' => $now,
            ],
        ];
        $bundle['signals'] = [
            [
                'id' => (string) Str::ulid(),
                'command_id' => $signalCommandId,
                'command_sequence' => 2,
                'workflow_sequence' => 1,
                'name' => 'external-signal',
                'target_scope' => 'instance',
                'status' => 'applied',
                'outcome' => 'signal_received',
                'payload_codec' => $codec,
                'arguments' => $signalArguments,
                'received_at' => $now,
                'applied_at' => $now,
                'closed_at' => $now,
            ],
        ];
        $bundle['updates'] = [
            [
                'id' => (string) Str::ulid(),
                'command_id' => $updateCommandId,
                'command_sequence' => 3,
                'workflow_sequence' => 2,
                'name' => 'external-update',
                'target_scope' => 'instance',
                'status' => 'completed',
                'outcome' => 'update_completed',
                'payload_codec' => $codec,
                'arguments' => $updateArguments,
                'result' => $updateResult,
                'accepted_at' => $now,
                'applied_at' => $now,
                'closed_at' => $now,
            ],
        ];
        $bundle = $this->resealBundle($bundle);
        $this->clearWorkflowState();

        $report = EmbeddedV2HistoryImport::import($bundle);

        $this->assertSame('imported', $report['status']);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->findOrFail($runId);
        /** @var WorkflowCommand $command */
        $command = WorkflowCommand::query()->findOrFail($commandId);
        /** @var WorkflowSignal $signal */
        $signal = WorkflowSignal::query()->where('workflow_command_id', $signalCommandId)->firstOrFail();
        /** @var WorkflowUpdate $update */
        $update = WorkflowUpdate::query()->where('workflow_command_id', $updateCommandId)->firstOrFail();

        $this->assertSameJsonObject($runArguments, ExternalPayloads::storedEnvelope($run->arguments));
        $this->assertSameJsonObject($commandPayload, ExternalPayloads::storedEnvelope($command->payload));
        $this->assertSameJsonObject($signalArguments, ExternalPayloads::storedEnvelope($signal->arguments));
        $this->assertSameJsonObject($updateArguments, ExternalPayloads::storedEnvelope($update->arguments));
        $this->assertSameJsonObject($updateResult, ExternalPayloads::storedEnvelope($update->result));

        $detail = RunDetailView::forRun($run->fresh());
        $detailCommand = collect($detail['commands'])->firstWhere('id', $commandId);
        $detailSignalCommand = collect($detail['commands'])->firstWhere('id', $signalCommandId);
        $detailUpdateCommand = collect($detail['commands'])->firstWhere('id', $updateCommandId);
        $detailSignal = collect($detail['signals'])->firstWhere('command_id', $signalCommandId);
        $detailUpdate = collect($detail['updates'])->firstWhere('command_id', $updateCommandId);

        $this->assertIsArray($detailCommand);
        $this->assertIsArray($detailSignalCommand);
        $this->assertIsArray($detailUpdateCommand);
        $this->assertIsArray($detailSignal);
        $this->assertIsArray($detailUpdate);
        $this->assertSameJsonObject($runArguments, $detail['arguments']);
        $this->assertSameJsonObject($commandPayload, $detailCommand['payload']);
        $this->assertNull($detailCommand['target_name']);
        $this->assertSame([], $detailCommand['validation_errors']);
        $this->assertSameJsonObject(
            $this->externalStorageEnvelope($codec, 'import-signal-command-payload'),
            $detailSignalCommand['payload']
        );
        $this->assertSameJsonObject(
            $this->externalStorageEnvelope($codec, 'import-update-command-payload'),
            $detailUpdateCommand['payload']
        );
        $this->assertSameJsonObject($signalArguments, $detailSignal['arguments']);
        $this->assertSameJsonObject($updateArguments, $detailUpdate['arguments']);
        $this->assertSameJsonObject($updateResult, $detailUpdate['result']);

        WorkflowSignal::query()->where('workflow_command_id', $signalCommandId)->delete();
        WorkflowUpdate::query()->where('workflow_command_id', $updateCommandId)->delete();

        $fallbackSignal = collect(RunSignalView::forRun($run->fresh()))->firstWhere('command_id', $signalCommandId);
        $fallbackUpdate = collect(RunUpdateView::forRun($run->fresh()))->firstWhere('command_id', $updateCommandId);

        $this->assertIsArray($fallbackSignal);
        $this->assertIsArray($fallbackUpdate);
        $this->assertSameJsonObject(
            $this->externalStorageEnvelope($codec, 'import-signal-command-payload'),
            $fallbackSignal['arguments']
        );
        $this->assertNull($fallbackSignal['name']);
        $this->assertSame([], $fallbackSignal['validation_errors']);
        $this->assertSameJsonObject(
            $this->externalStorageEnvelope($codec, 'import-update-command-payload'),
            $fallbackUpdate['arguments']
        );
        $this->assertNull($fallbackUpdate['name']);
        $this->assertSame([], $fallbackUpdate['validation_errors']);
    }

    /**
     * @return array{array<string, mixed>, string}
     */
    private function messageBundle(string $kind): array
    {
        $bundle = $this->runningBundleWithOpenWork();
        $bundle['workflow']['namespace'] = 'tenant-a';
        $commandId = (string) Str::ulid();
        $messageId = (string) Str::ulid();
        $bundle['commands'] = [[
            'id' => $commandId,
            'type' => $kind,
            'status' => 'accepted',
            'sequence' => 1,
            'payload_codec' => CodecRegistry::defaultCodec(),
            'accepted_at' => now()
                ->toJSON(),
        ]];
        $bundle[$kind === 'signal' ? 'signals' : 'updates'] = [[
            'id' => $messageId,
            'command_id' => $commandId,
            'name' => 'imported-message',
            'status' => $kind === 'signal' ? 'received' : 'accepted',
            'arguments' => Serializer::serialize(['fixture']),
            'payload_codec' => CodecRegistry::defaultCodec(),
            'received_at' => now()
                ->toJSON(),
            'accepted_at' => now()
                ->toJSON(),
        ]];
        if ($kind === 'signal') {
            $bundle['history_events'][] = [
                'id' => (string) Str::ulid(),
                'sequence' => 2,
                'type' => 'SignalWaitOpened',
                'payload' => [
                    'sequence' => 1,
                    'signal_name' => 'imported-message',
                    'signal_wait_id' => 'import-wait',
                ],
                'recorded_at' => now()
                    ->toJSON(),
            ];
            $bundle['tasks'][0]['payload'] = [
                'resume_source_kind' => 'workflow_signal',
                'workflow_signal_id' => $messageId,
                'signal_name' => 'imported-message',
                'signal_wait_id' => 'import-wait',
            ];
        }

        return [$this->resealBundle($bundle), $messageId];
    }

    private function otherMessageCommand(string $kind, ?string $namespace): WorkflowCommand
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'other-' . Str::ulid(),
            'workflow_type' => 'other',
            'workflow_class' => 'OtherWorkflow',
            'namespace' => $namespace,
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_type' => 'other',
            'workflow_class' => 'OtherWorkflow',
            'namespace' => $namespace,
            'status' => 'waiting',
            'payload_codec' => CodecRegistry::defaultCodec(),
        ]);

        return WorkflowCommand::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'workflow_run_id' => $run->id,
            'command_type' => $kind,
            'status' => 'accepted',
            'accepted_at' => now(),
            'message_sequence' => 1,
        ])->refresh();
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function applyImportedMessage(array $bundle, string $kind, string $messageId): void
    {
        $bridge = app(DefaultWorkflowTaskBridge::class);
        $taskId = $bundle['tasks'][0]['id'];
        $this->assertTrue($bridge->claimStatus($taskId)['claimed']);
        $commands = $kind === 'signal' ? [] : [[
            'type' => 'complete_update',
            'update_id' => $messageId,
            'result' => Serializer::serialize('completed'),
            'payload_codec' => CodecRegistry::defaultCodec(),
        ]];
        $result = $bridge->complete($taskId, $commands);
        $this->assertTrue($result['completed'], json_encode($result));
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowStateSnapshot(): array
    {
        $snapshot = [];
        foreach (['workflow_instances', 'workflow_runs', 'workflow_tasks', 'workflow_history_events',
            'workflow_commands', 'workflow_signal_records', 'workflow_updates', 'workflow_links',
            'activity_executions', 'activity_attempts', 'workflow_run_timers', 'workflow_failures',
            'workflow_run_summaries', 'workflow_run_waits', 'workflow_run_timeline_entries',
            'workflow_run_lineage_entries', 'workflow_run_timer_entries', 'workflow_memos', 'workflow_search_attributes'] as $table) {
            $snapshot[$table] = DB::table($table)->get()->map(static fn (object $row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function runningBundleWithOpenWork(TaskStatus $workflowTaskStatus = TaskStatus::Ready): array
    {
        $instance = WorkflowInstance::query()->create([
            'id' => 'embedded-import-instance-' . Str::lower((string) Str::ulid()),
            'workflow_class' => 'App\\Workflows\\EmbeddedImportWorkflow',
            'workflow_type' => 'orders.import',
            'namespace' => 'embedded-production',
            'business_key' => 'order-123',
            'run_count' => 1,
            'started_at' => now()
                ->subMinutes(10),
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => 'App\\Workflows\\EmbeddedImportWorkflow',
            'workflow_type' => 'orders.import',
            'namespace' => 'embedded-production',
            'business_key' => 'order-123',
            'status' => RunStatus::Waiting->value,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['order-123']),
            'connection' => 'redis',
            'queue' => 'orders',
            'started_at' => now()
                ->subMinutes(10),
            'last_progress_at' => now()
                ->subMinute(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowStarted, [
            'workflow_class' => 'App\\Workflows\\EmbeddedImportWorkflow',
            'workflow_type' => 'orders.import',
        ]);

        WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'embedded-production',
            'task_type' => TaskType::Workflow->value,
            'status' => $workflowTaskStatus->value,
            'payload' => [
                'reason' => 'import-test',
            ],
            'connection' => 'redis',
            'queue' => 'orders',
            'available_at' => now()
                ->subMinute(),
            'leased_at' => $workflowTaskStatus === TaskStatus::Leased ? now() : null,
            'lease_owner' => $workflowTaskStatus === TaskStatus::Leased ? 'embedded-worker-1' : null,
            'lease_expires_at' => $workflowTaskStatus === TaskStatus::Leased ? now()->addMinute() : null,
        ]);

        $activity = ActivityExecution::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 1,
            'activity_class' => 'App\\Activities\\ReserveInventory',
            'activity_type' => 'orders.reserve-inventory',
            'status' => ActivityStatus::Pending->value,
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['order-123']),
            'connection' => 'redis',
            'queue' => 'orders',
            'attempt_count' => 0,
        ]);

        WorkflowTask::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'namespace' => 'embedded-production',
            'task_type' => TaskType::Activity->value,
            'status' => TaskStatus::Ready->value,
            'payload' => [
                'activity_execution_id' => $activity->id,
            ],
            'connection' => 'redis',
            'queue' => 'orders',
            'available_at' => now()
                ->subMinute(),
        ]);

        WorkflowTimer::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'sequence' => 2,
            'status' => TimerStatus::Pending->value,
            'delay_seconds' => 60,
            'fire_at' => now()
                ->addMinute(),
        ]);

        return HistoryExport::forRun($run->fresh());
    }

    private function clearWorkflowState(): void
    {
        foreach ([
            'workflow_run_summaries',
            'workflow_run_waits',
            'workflow_run_timeline_entries',
            'workflow_run_timer_entries',
            'workflow_run_lineage_entries',
            'workflow_search_attributes',
            'workflow_memos',
            'workflow_history_events',
            'workflow_tasks',
            'activity_attempts',
            'activity_executions',
            'workflow_run_timers',
            'workflow_failures',
            'workflow_links',
            'workflow_signal_records',
            'workflow_updates',
            'workflow_commands',
            'workflow_runs',
            'workflow_instances',
        ] as $table) {
            DB::table($table)->delete();
        }
    }

    private function createMemo(WorkflowRun $run, string $key, mixed $value): void
    {
        $memo = new WorkflowMemo([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => $key,
            'upserted_at_sequence' => 1,
            'inherited_from_parent' => false,
        ]);
        $memo->setValue($value);
        $memo->save();
    }

    private function createSearchAttribute(WorkflowRun $run, string $key, mixed $value): void
    {
        $attribute = new WorkflowSearchAttribute([
            'workflow_run_id' => $run->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'key' => $key,
            'upserted_at_sequence' => 1,
            'inherited_from_parent' => false,
        ]);
        $attribute->setTypedValueWithInference($value);
        $attribute->save();
    }

    /**
     * @return array{codec: string, external_storage: array{schema: string, uri: string, sha256: string, size_bytes: int, codec: string}}
     */
    private function externalStorageEnvelope(string $codec, string $label): array
    {
        return [
            'codec' => $codec,
            'external_storage' => [
                'schema' => ExternalPayloadReference::SCHEMA,
                'uri' => 'local://embedded-history-import-test/' . $label,
                'sha256' => hash('sha256', $label),
                'size_bytes' => strlen($label),
                'codec' => $codec,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private function resealBundle(array $bundle): array
    {
        unset($bundle['integrity']);

        $canonicalJson = json_encode(
            self::canonicalize($bundle),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        $bundle['integrity'] = [
            'canonicalization' => 'json-recursive-ksort-v1',
            'checksum_algorithm' => 'sha256',
            'checksum' => hash('sha256', $canonicalJson),
            'signature_algorithm' => null,
            'signature' => null,
            'key_id' => null,
        ];

        return $bundle;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonicalize($item), $value);
        }

        $canonical = [];

        foreach ($value as $key => $item) {
            $canonical[$key] = self::canonicalize($item);
        }

        ksort($canonical, SORT_STRING);

        return $canonical;
    }
}
