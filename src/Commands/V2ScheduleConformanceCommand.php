<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;
use JsonException;
use Throwable;
use Workflow\V2\Client\ControlPlaneClient;
use Workflow\V2\Exceptions\ControlPlaneRequestException;
use Workflow\V2\Support\PlatformConformanceSuite;

class V2ScheduleConformanceCommand extends Command
{
    protected $signature = 'workflow:v2:schedule-conformance
        {--server-url= : Base URL for the standalone server under test}
        {--token= : Bearer token for control-plane requests}
        {--namespace=default : Namespace for the PHP schedule surface probe}
        {--schedule-id= : Schedule id to create or observe}
        {--task-queue=schedules : Task queue for the scheduled workflow action}
        {--workflow-type=workflow-v2-schedule-conformance : Workflow type key for the scheduled workflow action}
        {--cron= : Cron expression for the schedule spec}
        {--run-id= : Stable run suffix for generated ids}
        {--artifact-version=* : Repeatable actor=version option for the published artifact tuple}
        {--artifact-source=* : Repeatable actor=source option proving the published artifact install channel}
        {--json : Emit a single machine-readable JSON report}
        {--output= : Write the JSON report to a file instead of stdout}';

    protected $description = 'Emit the Workflow PHP schedule conformance evidence shard';

    private const RESULT_SCHEMA = 'durable-workflow.v2.schedule-runtime.result';

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
     * @var list<string>
     */
    private const REQUIRED_SCENARIOS = [
        'published_artifact_install_only',
        'php_schedule_surface_create_or_observe',
        'php_schedule_surface_list_or_describe',
        'php_schedule_surface_claimed_controls',
        'php_schedule_surface_state_parity',
        'result_record_and_product_finding_routing',
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

    private ?string $resolvedRunId = null;

    private ?string $resolvedScheduleId = null;

    public function __construct(
        private readonly Filesystem $files,
        private readonly HttpFactory $http,
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

        foreach ($this->livePhpScenarios() as $scenario) {
            $this->appendScenario($scenarioResults, $findings, $findingLinks, $scenario);
        }

        $this->appendScenario(
            $scenarioResults,
            $findings,
            $findingLinks,
            $this->resultRecordScenario($artifactVersions),
        );

        foreach (self::REQUIRED_SCENARIOS as $scenarioId) {
            if (isset($scenarioResults[$scenarioId])) {
                continue;
            }

            $this->appendScenario(
                $scenarioResults,
                $findings,
                $findingLinks,
                $this->failedScenario(
                    $scenarioId,
                    sprintf('PHP schedule conformance scenario [%s] was not emitted.', $scenarioId),
                    ['emitted_scenarios' => array_keys($scenarioResults)],
                    'workflow',
                ),
            );
        }

        $hasFailures = self::hasScenarioFailures($scenarioResults);
        $finishedAt = self::timestamp();
        $report = [
            'schema' => self::RESULT_SCHEMA,
            'schema_version' => self::RESULT_VERSION,
            'suite_version' => PlatformConformanceSuite::VERSION,
            'coverage_scope' => 'workflow-php-schedule-shard',
            'outcome' => $hasFailures ? 'fail' : 'non_passing',
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'generated_at' => $finishedAt,
            'artifact_versions' => $artifactVersions,
            'artifact_sources' => $artifactSources,
            'topology' => [
                'namespace' => $this->namespace(),
                'task_queue' => $this->taskQueue(),
                'schedule_id' => $this->scheduleId(),
                'workflow_type' => $this->workflowType(),
            ],
            'runtime_matrix' => [
                'runtimes' => ['workflow-php'],
                'client_paths' => ['workflow-php-control-plane-client'],
                'surface_cells' => [
                    [
                        'cell' => 'php_schedule_surface',
                        'scenarios' => [
                            'php_schedule_surface_create_or_observe',
                            'php_schedule_surface_list_or_describe',
                            'php_schedule_surface_claimed_controls',
                            'php_schedule_surface_state_parity',
                        ],
                    ],
                ],
            ],
            'php_schedule_surface' => [
                'create_or_observe' => $scenarioResults['php_schedule_surface_create_or_observe']['observed_outputs'] ?? [],
                'list_or_describe' => $scenarioResults['php_schedule_surface_list_or_describe']['observed_outputs'] ?? [],
                'claimed_controls' => $scenarioResults['php_schedule_surface_claimed_controls']['observed_outputs'] ?? [],
                'state_parity' => $scenarioResults['php_schedule_surface_state_parity']['observed_outputs'] ?? [],
            ],
            'scenario_results' => array_values($scenarioResults),
            'findings' => $findings,
            'finding_links' => $findingLinks,
        ];

        $this->emit($report);

        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function livePhpScenarios(): array
    {
        $serverUrl = $this->stringOption('server-url');
        if ($serverUrl === null) {
            $missing = ['server-url'];

            return [
                $this->failedScenario(
                    'php_schedule_surface_create_or_observe',
                    'PHP schedule conformance cannot run without server connection options.',
                    ['missing_options' => $missing],
                    'workflow',
                ),
                $this->failedScenario(
                    'php_schedule_surface_list_or_describe',
                    'PHP schedule list/describe evidence cannot run without server connection options.',
                    ['missing_options' => $missing],
                    'workflow',
                ),
                $this->failedScenario(
                    'php_schedule_surface_claimed_controls',
                    'PHP schedule control evidence cannot run without server connection options.',
                    ['missing_options' => $missing],
                    'workflow',
                ),
                $this->failedScenario(
                    'php_schedule_surface_state_parity',
                    'PHP schedule state parity cannot run without server connection options.',
                    ['missing_options' => $missing],
                    'workflow',
                ),
            ];
        }

        try {
            return $this->exerciseLivePhpScheduleSurface($serverUrl);
        } catch (Throwable $exception) {
            $evidence = [
                'exception_class' => $exception::class,
                'message' => $exception->getMessage(),
            ];

            return [
                $this->failedScenario(
                    'php_schedule_surface_create_or_observe',
                    'PHP schedule conformance failed before create/observe evidence completed.',
                    $evidence,
                    'workflow',
                ),
                $this->failedScenario(
                    'php_schedule_surface_list_or_describe',
                    'PHP schedule list/describe evidence failed.',
                    $evidence,
                    'workflow',
                ),
                $this->failedScenario(
                    'php_schedule_surface_claimed_controls',
                    'PHP schedule control evidence failed.',
                    $evidence,
                    'workflow',
                ),
                $this->failedScenario(
                    'php_schedule_surface_state_parity',
                    'PHP schedule state parity evidence failed.',
                    $evidence,
                    'workflow',
                ),
            ];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exerciseLivePhpScheduleSurface(string $serverUrl): array
    {
        $client = new ControlPlaneClient(
            $this->http,
            $serverUrl,
            $this->stringOption('token'),
            $this->namespace(),
        );
        $scheduleId = $this->scheduleId();
        $spec = [
            'cron_expressions' => [$this->cronExpression()],
            'timezone' => 'UTC',
        ];
        $action = [
            'workflow_type' => $this->workflowType(),
            'task_queue' => $this->taskQueue(),
            'input' => [[
                'source' => 'workflow-php-schedule-conformance',
                'run_id' => $this->runId(),
            ]],
        ];

        $createOutcome = $this->createOrObserve($client, $scheduleId, $spec, $action);
        $describeAfterCreate = $client->describeSchedule($scheduleId);
        $listAfterCreate = $client->listSchedules(['page_size' => 100]);
        $listedSchedule = self::findScheduleInList($listAfterCreate, $scheduleId);
        $describedSchedule = self::scheduleRecord($describeAfterCreate);

        $createChecks = $this->scheduleStateChecks(
            $scheduleId,
            $spec,
            $describedSchedule,
            'describe_after_create',
        );
        $listChecks = $this->scheduleStateChecks(
            $scheduleId,
            $spec,
            $listedSchedule,
            'list_after_create',
        );

        $pause = $client->pauseSchedule($scheduleId, 'php schedule conformance pause');
        $describeAfterPause = $client->describeSchedule($scheduleId);
        $pauseRecord = self::scheduleRecord($describeAfterPause);

        $resume = $client->resumeSchedule($scheduleId, 'php schedule conformance resume');
        $describeAfterResume = $client->describeSchedule($scheduleId);
        $resumeRecord = self::scheduleRecord($describeAfterResume);

        $trigger = $client->triggerSchedule($scheduleId);
        $delete = $client->deleteSchedule($scheduleId);
        $listAfterDelete = $client->listSchedules(['page_size' => 100]);
        $afterDeleteRecord = self::findScheduleInList($listAfterDelete, $scheduleId);

        $controlChecks = $this->controlChecks($scheduleId, $pauseRecord, $resumeRecord, $trigger, $afterDeleteRecord);
        $parityChecks = $this->parityChecks($scheduleId, $spec, $describedSchedule, $listedSchedule, $pauseRecord, $resumeRecord);

        return [
            [
                'scenario_id' => 'php_schedule_surface_create_or_observe',
                'status' => $createChecks['passed'] ? 'pass' : 'fail',
                'observed_outputs' => [
                    'schedule_id' => $scheduleId,
                    'spec' => $spec,
                    'action' => $action,
                    'create_or_observe_response' => $createOutcome,
                    'describe_after_create' => $describeAfterCreate,
                    'semantic_checks' => $createChecks,
                ],
                'linked_findings' => $createChecks['passed']
                    ? []
                    : [$this->finding(
                        'php_schedule_surface_create_or_observe',
                        'PHP schedule create/observe response did not prove requested schedule state.',
                        ['semantic_checks' => $createChecks, 'response' => $createOutcome],
                        'workflow',
                    )],
            ],
            [
                'scenario_id' => 'php_schedule_surface_list_or_describe',
                'status' => $listChecks['passed'] ? 'pass' : 'fail',
                'observed_outputs' => [
                    'list_after_create' => $listAfterCreate,
                    'listed_schedule' => $listedSchedule,
                    'described_schedule' => $describedSchedule,
                    'semantic_checks' => $listChecks,
                ],
                'linked_findings' => $listChecks['passed']
                    ? []
                    : [$this->finding(
                        'php_schedule_surface_list_or_describe',
                        'PHP schedule list/describe responses did not agree with requested schedule state.',
                        ['semantic_checks' => $listChecks, 'list_response' => $listAfterCreate],
                        'workflow',
                    )],
            ],
            [
                'scenario_id' => 'php_schedule_surface_claimed_controls',
                'status' => $controlChecks['passed'] ? 'pass' : 'fail',
                'observed_outputs' => [
                    'claimed_controls' => ['pause', 'resume', 'trigger', 'delete'],
                    'pause_response' => $pause,
                    'describe_after_pause' => $describeAfterPause,
                    'resume_response' => $resume,
                    'describe_after_resume' => $describeAfterResume,
                    'trigger_response' => $trigger,
                    'delete_response' => $delete,
                    'list_after_delete' => $listAfterDelete,
                    'semantic_checks' => $controlChecks,
                ],
                'linked_findings' => $controlChecks['passed']
                    ? []
                    : [$this->finding(
                        'php_schedule_surface_claimed_controls',
                        'PHP schedule claimed controls did not all produce observable server state.',
                        ['semantic_checks' => $controlChecks],
                        'workflow',
                    )],
            ],
            [
                'scenario_id' => 'php_schedule_surface_state_parity',
                'status' => $parityChecks['passed'] ? 'pass' : 'fail',
                'observed_outputs' => [
                    'fields_compared' => ['schedule_id', 'cadence', 'pause_state', 'last_fire_at', 'next_fire_at'],
                    'describe_after_create' => $describedSchedule,
                    'list_after_create' => $listedSchedule,
                    'describe_after_pause' => $pauseRecord,
                    'describe_after_resume' => $resumeRecord,
                    'semantic_checks' => $parityChecks,
                    'cli_state_agreement' => 'covered_by_full_schedules_harness',
                ],
                'linked_findings' => $parityChecks['passed']
                    ? []
                    : [$this->finding(
                        'php_schedule_surface_state_parity',
                        'PHP schedule state did not agree across list and describe surfaces.',
                        ['semantic_checks' => $parityChecks],
                        'workflow',
                    )],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function createOrObserve(
        ControlPlaneClient $client,
        string $scheduleId,
        array $spec,
        array $action,
    ): array {
        try {
            $response = $client->createSchedule($scheduleId, $spec, $action, [
                'overlap_policy' => 'allow_all',
                'jitter_seconds' => 0,
                'memo' => ['conformance' => 'php_schedule_surface'],
                'search_attributes' => ['ScheduleSurface' => 'workflow-php'],
            ]);
            $response['observed_via'] = 'create';

            return $response;
        } catch (ControlPlaneRequestException $exception) {
            if (! in_array($exception->status(), [409, 422], true)) {
                throw $exception;
            }

            $described = $client->describeSchedule($scheduleId);
            $described['observed_via'] = 'describe_existing_after_create_rejection';
            $described['create_rejection'] = [
                'status' => $exception->status(),
                'reason' => $exception->reason(),
                'message' => $exception->getMessage(),
                'body' => $exception->body(),
            ];

            return $described;
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed>|null $record
     * @return array<string, mixed>
     */
    private function scheduleStateChecks(string $scheduleId, array $spec, ?array $record, string $surface): array
    {
        $actualId = self::scheduleIdFromRecord($record);
        $actualCadence = self::cadence($record);
        $expectedCadence = self::expectedCadence($spec);
        $failures = [];

        if ($record === null) {
            $failures[] = sprintf('%s did not include the schedule record', $surface);
        }

        if ($actualId === null) {
            $failures[] = sprintf('%s did not include schedule_id', $surface);
        } elseif ($actualId !== $scheduleId) {
            $failures[] = sprintf('%s schedule_id mismatch', $surface);
        }

        if ($expectedCadence === null) {
            $failures[] = 'requested schedule spec did not include cadence';
        } elseif ($actualCadence === null) {
            $failures[] = sprintf('%s did not include schedule cadence/spec', $surface);
        } elseif ($actualCadence !== $expectedCadence) {
            $failures[] = sprintf('%s cadence mismatch', $surface);
        }

        return [
            'surface' => $surface,
            'passed' => $failures === [],
            'failures' => $failures,
            'expected' => [
                'schedule_id' => $scheduleId,
                'cadence' => $expectedCadence,
            ],
            'actual' => [
                'schedule_id' => $actualId,
                'cadence' => $actualCadence,
                'pause_state' => self::pauseState($record),
                'last_fire_at' => self::timestampField($record, 'last_fire_at'),
                'next_fire_at' => self::timestampField($record, 'next_fire_at'),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $pauseRecord
     * @param array<string, mixed>|null $resumeRecord
     * @param array<string, mixed> $trigger
     * @param array<string, mixed>|null $afterDeleteRecord
     * @return array<string, mixed>
     */
    private function controlChecks(
        string $scheduleId,
        ?array $pauseRecord,
        ?array $resumeRecord,
        array $trigger,
        ?array $afterDeleteRecord,
    ): array {
        $pauseState = self::pauseState($pauseRecord);
        $resumeState = self::pauseState($resumeRecord);
        $triggerRecord = self::scheduleRecord($trigger) ?? $trigger;
        $triggerScheduleId = self::scheduleIdFromRecord($triggerRecord);
        $triggerWorkflowId = self::triggerWorkflowId($trigger);
        $failures = [];

        if ($pauseState !== 'paused') {
            $failures[] = 'pause did not produce paused schedule state';
        }

        if ($resumeState === null) {
            $failures[] = 'resume did not expose active schedule state';
        } elseif ($resumeState !== 'active') {
            $failures[] = 'resume did not produce active schedule state';
        }

        if ($triggerScheduleId !== null && $triggerScheduleId !== $scheduleId) {
            $failures[] = 'trigger response schedule_id did not match requested schedule';
        }

        if ($triggerScheduleId === null && $triggerWorkflowId === null) {
            $failures[] = 'trigger response did not identify the requested schedule or a triggered workflow';
        }

        if ($afterDeleteRecord !== null) {
            $failures[] = 'delete did not remove schedule from list output';
        }

        return [
            'passed' => $failures === [],
            'failures' => $failures,
            'pause_state_after_pause' => $pauseState,
            'pause_state_after_resume' => $resumeState,
            'trigger_schedule_id' => $triggerScheduleId,
            'trigger_workflow_id' => $triggerWorkflowId,
            'trigger_schedule_id_matches_request' => $triggerScheduleId === $scheduleId,
            'trigger_identified_requested_schedule_or_workflow' => $triggerScheduleId === $scheduleId || $triggerWorkflowId !== null,
            'deleted_schedule_absent_from_list' => $afterDeleteRecord === null,
        ];
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed>|null $described
     * @param array<string, mixed>|null $listed
     * @param array<string, mixed>|null $paused
     * @param array<string, mixed>|null $resumed
     * @return array<string, mixed>
     */
    private function parityChecks(
        string $scheduleId,
        array $spec,
        ?array $described,
        ?array $listed,
        ?array $paused,
        ?array $resumed,
    ): array {
        $expectedCadence = self::expectedCadence($spec);
        $describedState = self::stateSnapshot($described);
        $listedState = self::stateSnapshot($listed);
        $failures = [];

        foreach (['describe' => $describedState, 'list' => $listedState] as $surface => $state) {
            if ($state['schedule_id'] === null) {
                $failures[] = sprintf('%s did not include schedule_id', $surface);
            } elseif ($state['schedule_id'] !== $scheduleId) {
                $failures[] = sprintf('%s schedule_id does not match requested id', $surface);
            }

            if ($expectedCadence === null) {
                $failures[] = 'requested schedule spec did not include cadence';
            } elseif ($state['cadence'] === null) {
                $failures[] = sprintf('%s did not include schedule cadence/spec', $surface);
            } elseif ($state['cadence'] !== $expectedCadence) {
                $failures[] = sprintf('%s cadence does not match requested cadence', $surface);
            }

            if ($state['pause_state'] === null) {
                $failures[] = sprintf('%s did not include pause state', $surface);
            }
        }

        foreach (['schedule_id', 'cadence', 'pause_state'] as $field) {
            if ($describedState[$field] !== null && $listedState[$field] !== null && $describedState[$field] !== $listedState[$field]) {
                $failures[] = sprintf('%s differs between describe and list', $field);
            }
        }

        foreach (['last_fire_at', 'next_fire_at'] as $field) {
            if (($describedState[$field] === null) !== ($listedState[$field] === null)) {
                $failures[] = sprintf('%s was exposed by only one of describe/list', $field);
            } elseif ($describedState[$field] !== null && $describedState[$field] !== $listedState[$field]) {
                $failures[] = sprintf('%s differs between describe and list', $field);
            }
        }

        return [
            'passed' => $failures === [],
            'failures' => $failures,
            'expected' => [
                'schedule_id' => $scheduleId,
                'cadence' => $expectedCadence,
            ],
            'describe' => $describedState,
            'list' => $listedState,
            'after_pause' => self::stateSnapshot($paused),
            'after_resume' => self::stateSnapshot($resumed),
        ];
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array<string, mixed>
     */
    private static function stateSnapshot(?array $record): array
    {
        return [
            'schedule_id' => self::scheduleIdFromRecord($record),
            'cadence' => self::cadence($record),
            'pause_state' => self::pauseState($record),
            'last_fire_at' => self::timestampField($record, 'last_fire_at'),
            'next_fire_at' => self::timestampField($record, 'next_fire_at'),
        ];
    }

    /**
     * @param array<string, mixed> $artifactVersions
     * @return array<string, mixed>
     */
    private function resultRecordScenario(array $artifactVersions): array
    {
        return [
            'scenario_id' => 'result_record_and_product_finding_routing',
            'status' => 'pass',
            'observed_outputs' => [
                'coverage_scope' => 'workflow-php-schedule-shard',
                'product_evidence_owner' => 'workflow',
                'finding_owner_when_php_surface_fails' => 'workflow',
                'artifact_versions' => $artifactVersions,
            ],
        ];
    }

    /**
     * @param array<string, string> $artifactVersions
     * @param array<string, string> $artifactSources
     * @return array<string, mixed>
     */
    private function publishedArtifactScenario(array $artifactVersions, array $artifactSources): array
    {
        $missingVersions = [];
        $missingSources = [];
        $rejectedVersions = [];
        $forbiddenSources = [];
        $untrustedSources = [];

        foreach (self::REQUIRED_ARTIFACTS as $artifact) {
            $version = self::artifactMetadata($artifactVersions, $artifact);
            if ($version === null) {
                $missingVersions[] = $artifact;
            } else {
                $reason = self::unpublishedVersionReason($version);
                if ($reason !== null) {
                    $rejectedVersions[$artifact] = $reason;
                }
            }

            $source = self::artifactMetadata($artifactSources, $artifact);
            if ($source === null) {
                $missingSources[] = $artifact;
            } elseif (self::isLocalArtifactSource($source)) {
                $forbiddenSources[$artifact] = $source;
            } elseif (! self::isPublishedArtifactSource($artifact, $source)) {
                $untrustedSources[$artifact] = $source;
            }
        }

        $passed = $missingVersions === []
            && $missingSources === []
            && $rejectedVersions === []
            && $forbiddenSources === []
            && $untrustedSources === [];

        return [
            'scenario_id' => 'published_artifact_install_only',
            'status' => $passed ? 'pass' : 'fail',
            'observed_outputs' => [
                'server_image' => self::artifactMetadata($artifactVersions, 'server'),
                'cli_release' => self::artifactMetadata($artifactVersions, 'cli'),
                'workflow_php_package' => self::artifactMetadata($artifactVersions, 'workflow-php'),
                'sdk_python_package' => self::artifactMetadata($artifactVersions, 'sdk-python'),
                'waterline_artifact' => self::artifactMetadata($artifactVersions, 'waterline'),
                'artifact_versions' => $artifactVersions,
                'artifact_sources' => $artifactSources,
                'missing_artifact_versions' => $missingVersions,
                'missing_artifact_sources' => $missingSources,
                'rejected_versions' => $rejectedVersions,
                'forbidden_sources' => $forbiddenSources,
                'untrusted_sources' => $untrustedSources,
                'published_install_tuple_proven' => $passed,
            ],
            'linked_findings' => $passed
                ? []
                : [$this->finding(
                    'published_artifact_install_only',
                    'Published-artifact schedule conformance inputs are incomplete.',
                    [
                        'missing_artifact_versions' => $missingVersions,
                        'missing_artifact_sources' => $missingSources,
                        'rejected_versions' => $rejectedVersions,
                        'forbidden_sources' => $forbiddenSources,
                        'untrusted_sources' => $untrustedSources,
                    ],
                    'workflow',
                )],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function failedScenario(string $scenarioId, string $title, array $evidence, string $owner): array
    {
        return [
            'scenario_id' => $scenarioId,
            'status' => 'fail',
            'observed_outputs' => $evidence,
            'linked_findings' => [
                $this->finding($scenarioId, $title, $evidence, $owner),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private function finding(string $scenarioId, string $title, array $evidence, string $owner): array
    {
        return [
            'scenario_id' => $scenarioId,
            'title' => $title,
            'owner' => $owner,
            'evidence' => $evidence,
        ];
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

        $scenarioFindings = [];
        foreach (($scenario['linked_findings'] ?? []) as $finding) {
            if (is_array($finding)) {
                $scenarioFindings[] = $finding;
            }
        }

        if ($scenarioFindings !== []) {
            $scenario['linked_findings'] = $scenarioFindings;
            $findings = array_merge($findings, $scenarioFindings);
            $findingLinks[$scenarioId] = $scenarioFindings;
        } else {
            unset($scenario['linked_findings']);
        }

        $scenarioResults[$scenarioId] = $scenario;
    }

    /**
     * @param array<string, mixed> $report
     */
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
            'Workflow PHP schedule conformance shard: %s (%d/%d scenarios passed)',
            $report['outcome'] === 'fail' ? '<error>FAIL</error>' : '<info>SHARD PASS</info>',
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

    private function namespace(): string
    {
        return $this->stringOption('namespace') ?? 'default';
    }

    private function scheduleId(): string
    {
        $option = $this->stringOption('schedule-id');
        if ($option !== null) {
            return $option;
        }

        if ($this->resolvedScheduleId === null) {
            $this->resolvedScheduleId = 'php-schedule-' . $this->runId();
        }

        return $this->resolvedScheduleId;
    }

    private function taskQueue(): string
    {
        return $this->stringOption('task-queue') ?? 'schedules';
    }

    private function workflowType(): string
    {
        return $this->stringOption('workflow-type') ?? 'workflow-v2-schedule-conformance';
    }

    private function cronExpression(): string
    {
        return $this->stringOption('cron') ?? '*/5 * * * *';
    }

    private function runId(): string
    {
        $option = $this->stringOption('run-id');
        if ($option !== null) {
            return $option;
        }

        if ($this->resolvedRunId === null) {
            $this->resolvedRunId = 'php-sched-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        }

        return $this->resolvedRunId;
    }

    /**
     * @param array<string, array<string, mixed>> $scenarioResults
     */
    private static function hasScenarioFailures(array $scenarioResults): bool
    {
        foreach ($scenarioResults as $scenario) {
            if (($scenario['status'] ?? null) === 'fail') {
                return true;
            }
        }

        return false;
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

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|null
     */
    private static function scheduleRecord(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        foreach (['schedule_id', 'scheduleId', 'spec', 'cadence', 'cron', 'cron_expression', 'status', 'paused'] as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload;
            }
        }

        foreach (['schedule', 'record', 'result', 'data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return self::scheduleRecord($payload[$key]) ?? $payload[$key];
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $listPayload
     * @return array<string, mixed>|null
     */
    private static function findScheduleInList(array $listPayload, string $scheduleId): ?array
    {
        $entries = $listPayload['schedules'] ?? $listPayload['data'] ?? [];
        if (! is_array($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $record = self::scheduleRecord($entry);
            if ($record !== null && self::scheduleIdFromRecord($record) === $scheduleId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private static function scheduleIdFromRecord(?array $record): ?string
    {
        if ($record === null) {
            return null;
        }

        foreach (['schedule_id', 'scheduleId', 'id'] as $key) {
            $value = $record[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        foreach (['schedule', 'record', 'result', 'data'] as $key) {
            $nested = $record[$key] ?? null;
            if (is_array($nested)) {
                $value = self::scheduleIdFromRecord($nested);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private static function cadence(?array $record): ?string
    {
        if ($record === null) {
            return null;
        }

        foreach (['cadence', 'cron', 'cron_expression', 'cronExpression'] as $key) {
            $value = $record[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $direct = self::expectedCadence($record);
        if ($direct !== null) {
            return $direct;
        }

        $spec = $record['spec'] ?? null;
        if (is_array($spec)) {
            return self::expectedCadence($spec);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function expectedCadence(array $spec): ?string
    {
        $cronExpressions = $spec['cron_expressions'] ?? null;
        if (is_array($cronExpressions)) {
            foreach ($cronExpressions as $expression) {
                if (is_string($expression) && $expression !== '') {
                    return $expression;
                }
            }
        }

        $camelCronExpressions = $spec['cronExpressions'] ?? null;
        if (is_array($camelCronExpressions)) {
            foreach ($camelCronExpressions as $expression) {
                if (is_string($expression) && $expression !== '') {
                    return $expression;
                }
            }
        }

        foreach (['cron', 'cron_expression', 'cronExpression'] as $key) {
            $value = $spec[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $intervals = $spec['intervals'] ?? null;
        if (is_array($intervals) && isset($intervals[0]) && is_array($intervals[0])) {
            $every = $intervals[0]['every'] ?? null;

            return is_string($every) && $every !== '' ? $every : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private static function pauseState(?array $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $paused = $record['paused'] ?? null;
        if (is_bool($paused)) {
            return $paused ? 'paused' : 'active';
        }

        $state = $record['state'] ?? null;
        if (is_array($state)) {
            $statePaused = $state['paused'] ?? null;
            if (is_bool($statePaused)) {
                return $statePaused ? 'paused' : 'active';
            }
        }

        $status = self::stringValue($record['status'] ?? null);
        if ($status !== null) {
            return strtolower($status) === 'paused' ? 'paused' : strtolower($status);
        }

        if (is_array($state)) {
            $stateStatus = self::stringValue($state['status'] ?? null);
            if ($stateStatus !== null) {
                return strtolower($stateStatus) === 'paused' ? 'paused' : strtolower($stateStatus);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private static function timestampField(?array $record, string $field): ?string
    {
        if ($record === null) {
            return null;
        }

        $aliases = match ($field) {
            'last_fire_at' => ['last_fire_at', 'last_fired_at', 'last_fire'],
            'next_fire_at' => ['next_fire_at', 'next_fire'],
            default => [$field],
        };

        foreach ($aliases as $alias) {
            $value = $record[$alias] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $info = $record['info'] ?? null;
        if (is_array($info)) {
            foreach ($aliases as $alias) {
                $value = $info[$alias] ?? null;
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $trigger
     */
    private static function triggerWorkflowId(array $trigger): ?string
    {
        $result = isset($trigger['result']) && is_array($trigger['result']) ? $trigger['result'] : [];
        $run = isset($trigger['run']) && is_array($trigger['run']) ? $trigger['run'] : [];
        $workflow = isset($trigger['workflow']) && is_array($trigger['workflow']) ? $trigger['workflow'] : [];
        $execution = isset($trigger['execution']) && is_array($trigger['execution']) ? $trigger['execution'] : [];

        return self::firstStringValue(
            $trigger['workflow_id'] ?? null,
            $trigger['workflowId'] ?? null,
            $trigger['workflow_run_id'] ?? null,
            $trigger['workflowRunId'] ?? null,
            $trigger['run_id'] ?? null,
            $trigger['runId'] ?? null,
            $result['workflow_id'] ?? null,
            $result['workflowId'] ?? null,
            $result['workflow_run_id'] ?? null,
            $result['workflowRunId'] ?? null,
            $result['run_id'] ?? null,
            $result['runId'] ?? null,
            $run['workflow_id'] ?? null,
            $run['workflowId'] ?? null,
            $run['workflow_run_id'] ?? null,
            $run['workflowRunId'] ?? null,
            $run['run_id'] ?? null,
            $run['runId'] ?? null,
            $run['id'] ?? null,
            $workflow['workflow_id'] ?? null,
            $workflow['workflowId'] ?? null,
            $workflow['run_id'] ?? null,
            $workflow['runId'] ?? null,
            $execution['workflow_id'] ?? null,
            $execution['workflowId'] ?? null,
            $execution['workflow_instance_id'] ?? null,
            $execution['workflowInstanceId'] ?? null,
            $execution['run_id'] ?? null,
            $execution['runId'] ?? null,
        );
    }

    private static function firstStringValue(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $string = self::stringValue($value);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
