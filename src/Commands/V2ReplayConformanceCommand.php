<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Conformance\ReplayConformanceBookingActivity;
use Workflow\V2\Conformance\ReplayConformanceCancelActivity;
use Workflow\V2\Conformance\ReplayConformanceDivergentWorkflow;
use Workflow\V2\Conformance\ReplayConformanceFailingChildWorkflow;
use Workflow\V2\Conformance\ReplayConformanceGreetingActivity;
use Workflow\V2\Conformance\ReplayConformanceVersionedActivityV3;
use Workflow\V2\Conformance\ReplayConformanceWorkflow;
use Workflow\V2\Support\ActivityCall;
use Workflow\V2\Support\BundleIntegrityVerifier;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\PlatformConformanceSuite;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\ReplayDiff;
use Workflow\V2\Support\WorkflowReplayer;

class V2ReplayConformanceCommand extends Command
{
    protected $signature = 'workflow:v2:replay-conformance
        {--artifact-version=* : Repeatable actor=version option for the published artifact tuple}
        {--artifact-source=* : Repeatable actor=source option proving the published artifact install channel}
        {--json : Emit a single machine-readable JSON report}
        {--output= : Write the JSON report to a file instead of stdout}';

    protected $description = 'Emit the Workflow PHP deterministic replay conformance evidence shard';

    private const RESULT_SCHEMA = 'durable-workflow.v2.replay-conformance.result';

    private const RESULT_VERSION = 1;

    /**
     * @var list<string>
     */
    private const REQUIRED_ARTIFACTS = [
        'server',
        'cli',
        'workflow-php',
        'sdk-python',
        'waterline',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const PUBLISHED_ARTIFACT_SOURCES = [
        'server' => [
            'docker_image',
            'docker_registry',
            'oci_image',
            'published_docker_image',
            'registry_image',
        ],
        'cli' => [
            'github_release',
            'github_release_asset',
            'official_install_script',
            'release_asset',
        ],
        'workflow-php' => [
            'composer',
            'composer_package',
            'packagist',
            'packagist_package',
        ],
        'sdk-python' => [
            'pip_package',
            'pypi',
            'pypi_package',
            'python_package',
        ],
        'waterline' => [
            'composer',
            'composer_package',
            'packagist',
            'packagist_package',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const COMPLETED_SCENARIOS = [
        'activity' => 'php_completed_history_activity_replay',
        'signal-update' => 'php_completed_history_signal_update_replay',
        'wait-condition' => 'php_completed_history_wait_condition_replay',
        'version-marker' => 'php_completed_history_version_marker_replay',
        'saga-compensation' => 'php_completed_history_saga_compensation_replay',
    ];

    /**
     * @var array<string, string>
     */
    private const RESTART_SCENARIOS = [
        'completed-query' => 'php_worker_restart_completed_query',
        'activity' => 'php_worker_restart_activity_state',
        'signal-update' => 'php_worker_restart_signal_update_state',
        'wait-condition' => 'php_worker_restart_wait_condition_state',
        'version-marker' => 'php_worker_restart_version_marker_state',
        'saga-compensation' => 'php_worker_restart_saga_compensation_state',
    ];

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = self::timestamp();
        $artifactVersions = $this->artifactVersions();
        $artifactSources = $this->artifactSources();

        $scenarioResults = [];
        $findings = [];
        $findingLinks = [];

        $this->appendScenario(
            $scenarioResults,
            $findings,
            $findingLinks,
            $this->publishedArtifactScenario($artifactVersions, $artifactSources),
        );

        foreach (self::COMPLETED_SCENARIOS as $family => $scenarioId) {
            $this->appendScenario(
                $scenarioResults,
                $findings,
                $findingLinks,
                $this->completedHistoryScenario($scenarioId, $family, $artifactVersions),
            );
        }

        foreach (self::RESTART_SCENARIOS as $family => $scenarioId) {
            $this->appendScenario(
                $scenarioResults,
                $findings,
                $findingLinks,
                $this->workerRestartScenario($scenarioId, $family, $artifactVersions),
            );
        }

        foreach ([
            $this->codeDivergenceScenario($artifactVersions),
            $this->serverHistoryMutationScenario($artifactVersions),
            $this->malformedHistoryScenario($artifactVersions),
            $this->inFlightSignalTimingScenario($artifactVersions),
        ] as $scenario) {
            $this->appendScenario($scenarioResults, $findings, $findingLinks, $scenario);
        }

        $hasFailures = self::hasScenarioFailures($scenarioResults);
        $report = [
            'schema' => self::RESULT_SCHEMA,
            'schema_version' => self::RESULT_VERSION,
            'suite_version' => PlatformConformanceSuite::VERSION,
            'coverage_scope' => 'workflow-php-runtime-shard',
            'outcome' => $hasFailures ? 'fail' : 'pass',
            'started_at' => $startedAt,
            'finished_at' => self::timestamp(),
            'artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'runtime_matrix' => [
                'runtimes' => ['workflow-php'],
            ],
            'scenario_results' => array_values($scenarioResults),
            'completed_history_replay' => self::sectionSummary($scenarioResults, array_values(self::COMPLETED_SCENARIOS)),
            'worker_restart_replay' => self::sectionSummary($scenarioResults, array_values(self::RESTART_SCENARIOS)),
            'adversarial_replay' => self::sectionSummary($scenarioResults, [
                'php_code_divergence_refusal',
                'server_history_mutation_refusal',
                'malformed_history_refusal',
            ]),
            'in_flight_timing' => self::sectionSummary($scenarioResults, [
                'php_in_flight_signal_restart_timing',
            ]),
            'findings' => $findings,
            'finding_links' => $findingLinks,
        ];

        $this->emit($report);

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     * @param list<array<string, mixed>> $findings
     * @param array<string, list<array<string, mixed>>> $findingLinks
     * @param array<string, mixed> $scenario
     */
    private function appendScenario(
        array &$scenarioResults,
        array &$findings,
        array &$findingLinks,
        array $scenario,
    ): void {
        $scenarioId = self::stringValue($scenario['scenario_id'] ?? null);

        if ($scenarioId === null) {
            return;
        }

        $scenarioResults[$scenarioId] = $scenario;

        if (($scenario['status'] ?? null) === 'pass') {
            return;
        }

        $finding = [
            'scenario_id' => $scenarioId,
            'title' => sprintf('Workflow PHP replay conformance scenario [%s] did not pass', $scenarioId),
            'evidence' => $scenario['observed_outputs'] ?? $scenario['replay_diagnostics'] ?? [],
        ];
        $findings[] = $finding;
        $findingLinks[$scenarioId] = [$finding];
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     *
     * @return array<string, mixed>
     */
    private function publishedArtifactScenario(array $artifactVersions, array $artifactSources): array
    {
        $missingVersions = [];
        $missingSources = [];
        $placeholder = [];
        $rejectedVersions = [];
        $forbiddenSources = [];
        $untrustedSources = [];

        foreach (self::REQUIRED_ARTIFACTS as $artifact) {
            $version = self::artifactVersion($artifactVersions, $artifact);
            if ($version === null) {
                $missingVersions[] = $artifact;
            } else {
                $versionReason = self::unpublishedVersionReason($version);
                if ($versionReason !== null) {
                    $rejectedVersions[$artifact] = [
                        'version' => $version,
                        'reason' => $versionReason,
                    ];

                    if (str_starts_with($versionReason, 'placeholder')) {
                        $placeholder[$artifact] = $version;
                    }
                }
            }

            $source = self::artifactSource($artifactSources, $artifact);
            if ($source === null) {
                $missingSources[] = $artifact;
            } elseif (self::isLocalArtifactSource($source)) {
                $forbiddenSources[$artifact] = $source;
            } elseif (! self::isPublishedArtifactSource($artifact, $source)) {
                $untrustedSources[$artifact] = $source;
            }
        }

        $missing = array_values(array_unique(array_merge($missingVersions, $missingSources)));
        $status = $missing === [] && $rejectedVersions === [] && $forbiddenSources === [] && $untrustedSources === []
            ? 'pass'
            : 'fail';

        return $this->scenario(
            'published_artifact_install_only',
            $status,
            $artifactVersions,
            [
                'artifact_versions_recorded' => $artifactVersions,
                'artifact_sources' => $artifactSources,
                'missing_artifacts' => $missing,
                'missing_artifact_versions' => $missingVersions,
                'missing_artifact_sources' => $missingSources,
                'placeholder_versions' => $placeholder,
                'rejected_versions' => $rejectedVersions,
                'forbidden_sources' => $forbiddenSources,
                'untrusted_sources' => $untrustedSources,
                'published_artifacts_only' => $status === 'pass',
                'published_install_tuple_proven' => $status === 'pass',
            ],
        );
    }

    /**
     * @param array<string, string> $artifactVersions
     *
     * @return array<string, mixed>
     */
    private function completedHistoryScenario(string $scenarioId, string $family, array $artifactVersions): array
    {
        try {
            $case = $this->goldenCase($family);
            $bundle = $this->historyBundle($case['scenario']);
            $state = (new QueryStateReplayer())->query(
                (new WorkflowReplayer())->runFromHistoryExport($bundle),
                'currentState',
            );
            $matches = $state === $case['expected_state'];

            return $this->scenario(
                $scenarioId,
                $matches ? 'pass' : 'fail',
                $artifactVersions,
                [
                    'family' => $family,
                    'history_event_types' => self::historyEventTypes($bundle),
                    'expected_state' => $case['expected_state'],
                    'replayed_state' => $state,
                    'state_matches_fixture' => $matches,
                ],
            );
        } catch (Throwable $exception) {
            return $this->exceptionScenario($scenarioId, $artifactVersions, $exception);
        }
    }

    /**
     * @param array<string, string> $artifactVersions
     *
     * @return array<string, mixed>
     */
    private function workerRestartScenario(string $scenarioId, string $family, array $artifactVersions): array
    {
        try {
            $case = $family === 'completed-query'
                ? $this->goldenCase('activity')
                : $this->goldenCase($family);
            $bundle = $this->historyBundle($case['scenario']);
            $state = (new QueryStateReplayer())->query(
                (new WorkflowReplayer())->runFromHistoryExport($bundle),
                'currentState',
            );
            $matches = $state === $case['expected_state'];

            return $this->scenario(
                $scenarioId,
                $matches ? 'pass' : 'fail',
                $artifactVersions,
                [
                    'family' => $family,
                    'worker_restart_simulated' => true,
                    'original_query_result' => $case['expected_state'],
                    'replay_query_result' => $state,
                    'query_result_matches_original' => $matches,
                    'history_event_types' => self::historyEventTypes($bundle),
                ],
            );
        } catch (Throwable $exception) {
            return $this->exceptionScenario($scenarioId, $artifactVersions, $exception);
        }
    }

    /**
     * @param array<string, string> $artifactVersions
     *
     * @return array<string, mixed>
     */
    private function codeDivergenceScenario(array $artifactVersions): array
    {
        try {
            $bundle = $this->historyBundle('single-activity', ReplayConformanceDivergentWorkflow::class);
            $diff = (new ReplayDiff())->diffExport($bundle);
            $divergence = is_array($diff['divergence'] ?? null) ? $diff['divergence'] : [];
            $passes = ($diff['status'] ?? null) === ReplayDiff::STATUS_DRIFTED
                && ($diff['reason'] ?? null) === ReplayDiff::REASON_SHAPE_MISMATCH
                && $divergence !== [];

            return $this->scenario(
                'php_code_divergence_refusal',
                $passes ? 'pass' : 'fail',
                $artifactVersions,
                [
                    'observed_outcome' => 'non_determinism_error',
                    'workflow_sequence' => $divergence['workflow_sequence'] ?? null,
                    'expected_shape' => $divergence['expected_shape'] ?? null,
                    'recorded_event_types' => $divergence['recorded_event_types'] ?? [],
                    'message' => $divergence['message'] ?? null,
                    'replay_diff' => $diff,
                ],
            );
        } catch (Throwable $exception) {
            return $this->exceptionScenario('php_code_divergence_refusal', $artifactVersions, $exception);
        }
    }

    /**
     * @param array<string, string> $artifactVersions
     *
     * @return array<string, mixed>
     */
    private function serverHistoryMutationScenario(array $artifactVersions): array
    {
        try {
            $bundle = $this->historyBundle('single-activity');
            $bundle['history_events'][1]['payload']['result'] = self::payload('Hello, Grace!', self::codec());

            $integrity = BundleIntegrityVerifier::verify($bundle);
            $diff = (new ReplayDiff())->diffExport($bundle);
            $finding = self::firstFinding($integrity);
            $passes = ($integrity['status'] ?? null) === BundleIntegrityVerifier::STATUS_FAILED
                && $finding !== [];

            return $this->scenario(
                'server_history_mutation_refusal',
                $passes ? 'pass' : 'fail',
                $artifactVersions,
                [
                    'observed_outcome' => 'bundle_invalid_or_drifted',
                    'integrity' => [
                        'rule' => $finding['rule'] ?? 'integrity.checksum_mismatch',
                        'path' => $finding['path'] ?? 'integrity.checksum',
                        'status' => $integrity['status'] ?? null,
                    ],
                    'replay_diff' => [
                        'reason' => $diff['reason'] ?? 'integrity_failed_before_replay',
                        'status' => $diff['status'] ?? null,
                    ],
                    'message' => $finding['message'] ?? 'Mutated history bundle was refused by integrity verification.',
                ],
            );
        } catch (Throwable $exception) {
            return $this->exceptionScenario('server_history_mutation_refusal', $artifactVersions, $exception);
        }
    }

    /**
     * @param array<string, string> $artifactVersions
     *
     * @return array<string, mixed>
     */
    private function malformedHistoryScenario(array $artifactVersions): array
    {
        try {
            $bundle = $this->historyBundle('single-activity');
            unset($bundle['workflow']['run_id']);

            $integrity = BundleIntegrityVerifier::verify($bundle);
            $finding = self::firstFinding($integrity, 'workflow.run_id_missing') ?: self::firstFinding($integrity);
            $passes = ($integrity['status'] ?? null) === BundleIntegrityVerifier::STATUS_FAILED
                && $finding !== [];

            return $this->scenario(
                'malformed_history_refusal',
                $passes ? 'pass' : 'fail',
                $artifactVersions,
                [
                    'observed_outcome' => 'bundle_invalid_or_failed',
                    'integrity' => [
                        'rule' => $finding['rule'] ?? 'workflow.run_id_missing',
                        'path' => $finding['path'] ?? 'workflow.run_id',
                        'status' => $integrity['status'] ?? null,
                    ],
                    'message' => $finding['message'] ?? 'Malformed history bundle was refused by integrity verification.',
                ],
            );
        } catch (Throwable $exception) {
            return $this->exceptionScenario('malformed_history_refusal', $artifactVersions, $exception);
        }
    }

    /**
     * @param array<string, string> $artifactVersions
     *
     * @return array<string, mixed>
     */
    private function inFlightSignalTimingScenario(array $artifactVersions): array
    {
        try {
            $bundle = $this->historyBundle('signal-activity', ReplayConformanceWorkflow::class, false);
            $state = (new WorkflowReplayer())->replayExport($bundle);
            $current = $state->current;
            $nextDecision = $current instanceof ActivityCall
                ? $current->activity
                : ($current === null ? null : $current::class);
            $passes = $state->sequence === 2
                && $nextDecision === ReplayConformanceGreetingActivity::class;

            return $this->scenario(
                'php_in_flight_signal_restart_timing',
                $passes ? 'pass' : 'fail',
                $artifactVersions,
                [
                    'observed_outcome' => 'same_next_decision_after_replay',
                    'worker_restart_at' => 'after_signal_history_reload',
                    'signal_sent_at' => 'history_event:SignalApplied',
                    'history_reloaded_at' => 'workflow_replayer.replayExport',
                    'replayed_next_decision' => $nextDecision,
                    'replayed_sequence' => $state->sequence,
                    'expected_next_decision' => ReplayConformanceGreetingActivity::class,
                    'history_event_types' => self::historyEventTypes($bundle),
                ],
            );
        } catch (Throwable $exception) {
            return $this->exceptionScenario('php_in_flight_signal_restart_timing', $artifactVersions, $exception);
        }
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, mixed> $observedOutputs
     *
     * @return array<string, mixed>
     */
    private function scenario(
        string $scenarioId,
        string $status,
        array $artifactVersions,
        array $observedOutputs,
    ): array {
        return [
            'scenario_id' => $scenarioId,
            'status' => $status,
            'published_artifact_versions' => $artifactVersions,
            'implementation_identity' => [
                'runtime' => 'workflow-php',
                'package' => 'durable-workflow/workflow',
                'version' => self::artifactVersion($artifactVersions, 'workflow-php'),
            ],
            'runtime_matrix' => [
                'runtimes' => ['workflow-php'],
            ],
            'observed_outputs' => $observedOutputs,
            'replay_diagnostics' => $observedOutputs,
        ];
    }

    /**
     * @param array<string, string> $artifactVersions
     *
     * @return array<string, mixed>
     */
    private function exceptionScenario(string $scenarioId, array $artifactVersions, Throwable $exception): array
    {
        return $this->scenario(
            $scenarioId,
            'fail',
            $artifactVersions,
            [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ],
        );
    }

    /**
     * @return array{scenario: string, expected_state: array<string, mixed>}
     */
    private function goldenCase(string $family): array
    {
        return match ($family) {
            'activity' => [
                'scenario' => 'single-activity',
                'expected_state' => [
                    'stage' => 'completed',
                    'name' => null,
                    'greeting' => 'Hello, Ada!',
                    'approved' => false,
                    'version' => -1,
                    'version_result' => null,
                    'reservation_id' => null,
                    'events' => ['activity:Hello, Ada!'],
                ],
            ],
            'signal-update' => [
                'scenario' => 'signal-activity',
                'expected_state' => [
                    'stage' => 'completed',
                    'name' => 'Grace',
                    'greeting' => 'Hello, Grace!',
                    'approved' => false,
                    'version' => -1,
                    'version_result' => null,
                    'reservation_id' => null,
                    'events' => ['signal:Grace', 'activity:Hello, Grace!'],
                ],
            ],
            'wait-condition' => [
                'scenario' => 'wait-condition',
                'expected_state' => [
                    'stage' => 'approved',
                    'name' => null,
                    'greeting' => null,
                    'approved' => true,
                    'version' => -1,
                    'version_result' => null,
                    'reservation_id' => null,
                    'events' => ['approved', 'condition-satisfied'],
                ],
            ],
            'version-marker' => [
                'scenario' => 'version-marker',
                'expected_state' => [
                    'stage' => 'completed',
                    'name' => null,
                    'greeting' => null,
                    'approved' => false,
                    'version' => 2,
                    'version_result' => 'v3_result',
                    'reservation_id' => null,
                    'events' => ['version:2'],
                ],
            ],
            'saga-compensation' => [
                'scenario' => 'saga-compensation',
                'expected_state' => [
                    'stage' => 'compensated',
                    'name' => null,
                    'greeting' => null,
                    'approved' => false,
                    'version' => -1,
                    'version_result' => null,
                    'reservation_id' => 'inventory-id-456',
                    'events' => ['compensated:payment declined'],
                ],
            ],
            default => throw new \InvalidArgumentException("Unknown replay conformance family [{$family}]."),
        };
    }

    /**
     * @param class-string $workflowClass
     *
     * @return array<string, mixed>
     */
    private function historyBundle(
        string $scenario,
        string $workflowClass = ReplayConformanceWorkflow::class,
        bool $complete = true,
    ): array {
        $codec = self::codec();
        $runId = 'replay-conformance-' . str_replace('-', '_', $scenario);
        $events = $this->historyEvents($scenario, $codec, $complete);

        $bundle = [
            'schema' => HistoryExport::SCHEMA,
            'schema_version' => HistoryExport::SCHEMA_VERSION,
            'exported_at' => '2026-05-21T00:00:00.000000Z',
            'dedupe_key' => "{$runId}:1:2026-05-21T00:00:00.000000Z",
            'history_complete' => $complete,
            'workflow' => [
                'instance_id' => "{$runId}-instance",
                'run_id' => $runId,
                'run_number' => 1,
                'workflow_type' => ReplayConformanceWorkflow::TYPE_KEY,
                'workflow_class' => $workflowClass,
                'status' => $complete ? 'completed' : 'running',
                'last_history_sequence' => count($events),
                'started_at' => '2026-05-21T00:00:00.000000Z',
                'closed_at' => $complete ? '2026-05-21T00:01:00.000000Z' : null,
            ],
            'payloads' => [
                'codec' => $codec,
                'arguments' => [
                    'available' => true,
                    'data' => self::payload([$scenario], $codec),
                ],
                'output' => [
                    'available' => false,
                    'data' => null,
                ],
            ],
            'history_events' => $events,
            'waits' => [],
            'timeline' => [],
            'linked_intakes_scope' => 'selected_run',
            'linked_intakes' => [],
            'commands' => [],
            'signals' => [],
            'updates' => [],
            'tasks' => [],
            'activities' => [],
            'timers' => [],
            'failures' => [],
            'links' => [
                'projection_source' => 'rebuilt',
                'parents' => [],
                'children' => [],
            ],
            'redaction' => [
                'applied' => false,
                'policy' => null,
                'paths' => [],
            ],
            'codec_schemas' => [],
            'payload_manifest' => [
                'version' => 1,
                'entries' => [],
            ],
        ];
        $bundle['integrity'] = self::integrity($bundle);

        return $bundle;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyEvents(string $scenario, string $codec, bool $complete): array
    {
        $events = [
            $this->event(1, 'WorkflowStarted', []),
        ];

        switch ($scenario) {
            case 'single-activity':
                $events[] = $this->event(2, 'ActivityCompleted', [
                    'sequence' => 1,
                    'activity_type' => ReplayConformanceGreetingActivity::TYPE_KEY,
                    'result' => self::payload('Hello, Ada!', $codec),
                    'payload_codec' => $codec,
                ]);
                break;

            case 'signal-activity':
                $this->appendSignalActivityEvents($events, $codec, $complete);
                break;

            case 'wait-condition':
                $this->appendWaitConditionEvents($events, $codec);
                break;

            case 'version-marker':
                $this->appendVersionMarkerEvents($events, $codec);
                break;

            case 'saga-compensation':
                $this->appendSagaEvents($events, $codec);
                break;

            default:
                throw new \InvalidArgumentException("Unknown replay conformance scenario [{$scenario}].");
        }

        return $events;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function appendSignalActivityEvents(array &$events, string $codec, bool $complete): void
    {
        $events[] = $this->event(2, 'SignalApplied', [
            'sequence' => 1,
            'signal_name' => 'name-provided',
            'value' => self::payload('Grace', $codec),
            'payload_codec' => $codec,
        ]);

        if (! $complete) {
            return;
        }

        $events[] = $this->event(3, 'ActivityCompleted', [
            'sequence' => 2,
            'activity_type' => ReplayConformanceGreetingActivity::TYPE_KEY,
            'result' => self::payload('Hello, Grace!', $codec),
            'payload_codec' => $codec,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function appendWaitConditionEvents(array &$events, string $codec): void
    {
        $events[] = $this->event(2, 'ConditionWaitOpened', [
            'sequence' => 1,
            'condition_wait_id' => 'condition:1',
        ]);
        $events[] = $this->event(3, 'UpdateApplied', [
            'sequence' => 1,
            'update_name' => 'approve',
            'arguments' => self::payload([true], $codec),
            'payload_codec' => $codec,
        ]);
        $events[] = $this->event(4, 'ConditionWaitSatisfied', [
            'sequence' => 1,
            'condition_wait_id' => 'condition:1',
        ]);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function appendVersionMarkerEvents(array &$events, string $codec): void
    {
        $events[] = $this->event(2, 'VersionMarkerRecorded', [
            'sequence' => 1,
            'change_id' => 'golden-version',
            'version' => 2,
            'min_supported' => -1,
            'max_supported' => 2,
        ]);
        $events[] = $this->event(3, 'ActivityCompleted', [
            'sequence' => 2,
            'activity_type' => ReplayConformanceVersionedActivityV3::TYPE_KEY,
            'result' => self::payload('v3_result', $codec),
            'payload_codec' => $codec,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function appendSagaEvents(array &$events, string $codec): void
    {
        $events[] = $this->event(2, 'ActivityCompleted', [
            'sequence' => 1,
            'activity_type' => ReplayConformanceBookingActivity::TYPE_KEY,
            'result' => self::payload('inventory-id-456', $codec),
            'payload_codec' => $codec,
        ]);
        $events[] = $this->event(3, 'ChildRunFailed', [
            'sequence' => 2,
            'child_workflow_type' => ReplayConformanceFailingChildWorkflow::TYPE_KEY,
            'workflow_class' => ReplayConformanceFailingChildWorkflow::class,
            'message' => 'payment declined',
            'exception_class' => \RuntimeException::class,
        ]);
        $events[] = $this->event(4, 'ActivityCompleted', [
            'sequence' => 3,
            'activity_type' => ReplayConformanceCancelActivity::TYPE_KEY,
            'result' => self::payload('cancelled-inventory-id-456', $codec),
            'payload_codec' => $codec,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function event(int $sequence, string $type, array $payload): array
    {
        return [
            'id' => sprintf('replay-conformance-event-%02d', $sequence),
            'sequence' => $sequence,
            'type' => $type,
            'workflow_command_id' => null,
            'workflow_task_id' => null,
            'recorded_at' => sprintf('2026-05-21T00:00:%02d.000000Z', $sequence),
            'payload' => $payload,
        ];
    }

    private function emit(array $report): void
    {
        if ((bool) $this->option('json') || $this->stringOption('output') !== null) {
            $this->emitJson($report);

            return;
        }

        $this->renderHuman($report);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function emitJson(array $report): void
    {
        try {
            $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        $output = $this->stringOption('output');

        if ($output !== null) {
            $this->files->ensureDirectoryExists(dirname($output));
            $this->files->put($output, $json . PHP_EOL);

            return;
        }

        $this->line($json);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderHuman(array $report): void
    {
        $passed = count(array_filter(
            $report['scenario_results'],
            static fn (array $scenario): bool => ($scenario['status'] ?? null) === 'pass',
        ));
        $total = count($report['scenario_results']);

        $this->line(sprintf(
            'Workflow PHP replay conformance shard: %s (%d/%d scenarios passed)',
            count($report['findings']) === 0 ? '<info>PASS</info>' : '<error>FAIL</error>',
            $passed,
            $total,
        ));
        $this->line('Outcome: ' . $report['outcome']);
        $this->line('Schema: ' . $report['schema']);

        foreach ($report['scenario_results'] as $scenario) {
            $this->line(sprintf(
                '  [%s] %s',
                strtoupper((string) ($scenario['status'] ?? 'unknown')),
                (string) ($scenario['scenario_id'] ?? 'unknown'),
            ));
        }
    }

    /**
     * @return array<string, string>
     */
    private function artifactVersions(): array
    {
        return self::canonicalArtifactMetadata($this->keyValueOptions('artifact-version'));
    }

    /**
     * @return array<string, string>
     */
    private function artifactSources(): array
    {
        return self::canonicalArtifactMetadata($this->keyValueOptions('artifact-source'));
    }

    /**
     * @return array<string, string>
     */
    private function keyValueOptions(string $name): array
    {
        $values = $this->option($name);
        if (! is_array($values)) {
            $values = is_string($values) ? [$values] : [];
        }

        $parsed = [];
        foreach ($values as $value) {
            if (! is_string($value) || ! str_contains($value, '=')) {
                continue;
            }

            [$key, $entryValue] = explode('=', $value, 2);
            $key = trim($key);
            $entryValue = trim($entryValue);
            if ($key === '' || $entryValue === '') {
                continue;
            }

            $parsed[$key] = $entryValue;
        }

        return $parsed;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     * @param list<string> $scenarioIds
     *
     * @return array<string, mixed>
     */
    private static function sectionSummary(array $scenarioResults, array $scenarioIds): array
    {
        $statuses = [];
        foreach ($scenarioIds as $scenarioId) {
            $statuses[$scenarioId] = $scenarioResults[$scenarioId]['status'] ?? 'not_covered';
        }

        return [
            'scenario_statuses' => $statuses,
            'passed' => count(array_filter($statuses, static fn (string $status): bool => $status === 'pass')),
            'total' => count($statuses),
        ];
    }

    /**
     * @param list<array<string, mixed>> $scenarioResults
     */
    private static function hasScenarioFailures(array $scenarioResults): bool
    {
        foreach ($scenarioResults as $scenario) {
            if (($scenario['status'] ?? null) !== 'pass') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $versions
     */
    private static function artifactVersion(array $versions, string $artifact): ?string
    {
        return self::artifactMetadata($versions, $artifact);
    }

    /**
     * @param array<string, string> $sources
     */
    private static function artifactSource(array $sources, string $artifact): ?string
    {
        return self::artifactMetadata($sources, $artifact);
    }

    /**
     * @param array<string, string> $metadata
     */
    private static function artifactMetadata(array $metadata, string $artifact): ?string
    {
        $aliases = [
            'workflow-php' => ['workflow-php', 'workflow_php', 'workflow'],
            'sdk-python' => ['sdk-python', 'sdk_python', 'python'],
        ];

        foreach ($aliases[$artifact] ?? [$artifact] as $key) {
            if (isset($metadata[$key]) && $metadata[$key] !== '') {
                return $metadata[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $metadata
     *
     * @return array<string, string>
     */
    private static function canonicalArtifactMetadata(array $metadata): array
    {
        $canonical = [];

        foreach ($metadata as $artifact => $value) {
            $key = self::canonicalArtifactKey($artifact);
            $canonical[$key ?? $artifact] = $value;
        }

        return $canonical;
    }

    private static function canonicalArtifactKey(string $artifact): ?string
    {
        $normalized = str_replace('_', '-', strtolower(trim($artifact)));

        return match ($normalized) {
            'server' => 'server',
            'cli' => 'cli',
            'workflow', 'workflow-php' => 'workflow-php',
            'python', 'sdk-python' => 'sdk-python',
            'waterline' => 'waterline',
            default => null,
        };
    }

    private static function unpublishedVersionReason(string $version): ?string
    {
        $normalized = strtolower(trim($version));
        $localVersionPattern = '/(^|[^a-z0-9])(local|workspace|source|checkout|repo|path|dirty)([^a-z0-9]|$)/';
        $devVersionPattern = '/(^dev[-_.\/]|[-_.\/]dev($|[-_.\/])|@dev($|[^a-z0-9])|\.x-dev$|-dev$|9999999-dev)/';

        if ($normalized === '') {
            return 'empty_version';
        }

        if (preg_match('/<[^>]+>|\$\{[^}]+}|{{[^}]+}}/', $normalized) === 1) {
            return 'placeholder_template';
        }

        if (preg_match(
            '/(^|[^a-z0-9])(latest|current|head|unresolved|placeholder)([^a-z0-9]|$)/',
            $normalized,
        ) === 1) {
            return 'placeholder_label';
        }

        if (str_contains($normalized, '*')) {
            return 'wildcard_version';
        }

        if (
            $normalized === 'self.version'
            || preg_match($localVersionPattern, $normalized) === 1
        ) {
            return 'local_or_source_version';
        }

        if (
            preg_match($devVersionPattern, $normalized) === 1
            || preg_match('/^(main|master|trunk|v\d+)$/', $normalized) === 1
        ) {
            return 'dev_or_branch_version';
        }

        return null;
    }

    private static function isLocalArtifactSource(string $source): bool
    {
        $normalized = self::normalizeArtifactSource($source);

        return preg_match(
            '/(^|_)(dev|editable|local|path|repo|source|workspace|checkout)(_|$)/',
            $normalized,
        ) === 1;
    }

    private static function isPublishedArtifactSource(string $artifact, string $source): bool
    {
        $normalized = self::normalizeArtifactSource($source);

        return in_array($normalized, self::PUBLISHED_ARTIFACT_SOURCES[$artifact] ?? [], true);
    }

    private static function normalizeArtifactSource(string $source): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($source))) ?? '';

        return trim($normalized, '_');
    }

    private static function codec(): string
    {
        return CodecRegistry::defaultCodec();
    }

    private static function payload(mixed $value, string $codec): string
    {
        return Serializer::serializeWithCodec($codec, $value);
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    private static function integrity(array $bundle): array
    {
        unset($bundle['integrity']);
        $canonicalJson = json_encode(
            self::canonicalize($bundle),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        return [
            'canonicalization' => 'json-recursive-ksort-v1',
            'checksum_algorithm' => 'sha256',
            'checksum' => hash('sha256', $canonicalJson),
            'signature_algorithm' => null,
            'signature' => null,
            'key_id' => null,
        ];
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

    /**
     * @param array<string, mixed> $bundle
     * @return list<string>
     */
    private static function historyEventTypes(array $bundle): array
    {
        $events = is_array($bundle['history_events'] ?? null) ? $bundle['history_events'] : [];

        return array_values(array_filter(array_map(
            static fn (mixed $event): ?string => is_array($event) && is_string($event['type'] ?? null)
                ? $event['type']
                : null,
            $events,
        )));
    }

    /**
     * @param array<string, mixed> $integrity
     *
     * @return array<string, mixed>
     */
    private static function firstFinding(array $integrity, ?string $rule = null): array
    {
        $findings = is_array($integrity['findings'] ?? null) ? $integrity['findings'] : [];
        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            if ($rule === null || ($finding['rule'] ?? null) === $rule) {
                return $finding;
            }
        }

        return [];
    }

    private static function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
