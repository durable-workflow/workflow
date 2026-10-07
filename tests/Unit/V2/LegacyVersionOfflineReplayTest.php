<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestLegacyPatchReplayWorkflow;
use Tests\Fixtures\V2\TestQueryReplayGuardActivity;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\QueryStateReplayer;
use Workflow\V2\Support\ReplayDiff;
use Workflow\V2\Support\TimerCall;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Support\WorkflowReplayer;

final class LegacyVersionOfflineReplayTest extends TestCase
{
    #[DataProvider('legacyHistories')]
    public function testLegacyDefaultSharesTheRecordedStepWithoutMutatingHistory(
        string $patchBefore,
        bool $completed,
        string $legacyEvidence,
    ): void {
        $bundle = $this->bundle($patchBefore, $completed);
        if ($legacyEvidence === 'changed-fingerprint') {
            $bundle['history_events'][0]['payload']['workflow_definition_fingerprint'] =
                'sha256:' . hash('sha256', 'definition-before-patched-call');
        } elseif ($legacyEvidence === 'older-compatibility') {
            $bundle['workflow']['compatibility'] = 'build-before-patch';
            $bundle['history_events'][0]['payload']['workflow_definition_fingerprint'] =
                WorkflowDefinition::fingerprint(TestLegacyPatchReplayWorkflow::class);
            config([
                'workflows.v2.compatibility.current' => 'build-after-patch',
            ]);
        }
        $before = $bundle;
        $expected = [
            'patched' => false,
            'value' => 'recorded-result',
            'stage' => $completed ? 'completed' : 'waiting-for-timer',
        ];
        TestQueryReplayGuardActivity::$executions = 0;

        foreach ([1, 2] as $repetition) {
            $replayer = new WorkflowReplayer();
            $run = $replayer->runFromHistoryExport($bundle);
            $state = $replayer->replay($run);
            $this->assertSame($expected, $state->workflow->currentState());
            $this->assertSame($completed ? 3 : 2, $state->sequence);
            if ($completed) {
                $this->assertNull($state->current);
            } else {
                $this->assertInstanceOf(TimerCall::class, $state->current);
            }
            $this->assertSame($expected, (new QueryStateReplayer())->query($run, 'currentState'));
            $report = (new ReplayDiff())->diffExport($bundle);
            $this->assertSame(ReplayDiff::STATUS_REPLAYED, $report['status'], json_encode($report));
            $this->assertSame(ReplayDiff::REASON_NONE, $report['reason']);
            $this->assertSame(0, TestQueryReplayGuardActivity::$executions);
            $this->assertSame($before, $bundle);
        }
    }

    public static function legacyHistories(): array
    {
        $cases = [];
        foreach (['activity', 'timer'] as $position) {
            foreach ([false, true] as $completed) {
                foreach (['missing-fingerprint', 'changed-fingerprint', 'older-compatibility'] as $evidence) {
                    $cases[$position . '-' . ($completed ? 'completed' : 'waiting') . '-' . $evidence] = [
                        $position, $completed, $evidence,
                    ];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('invalidHistories')]
    public function testFallbackDoesNotHideMalformedMarkersOrStepDrift(string $case): void
    {
        $bundle = $this->bundle('activity', true);
        $reason = ReplayDiff::REASON_REPLAY_ERROR;
        if ($case === 'current-definition') {
            $bundle['history_events'][0]['payload']['workflow_definition_fingerprint'] =
                WorkflowDefinition::fingerprint(TestLegacyPatchReplayWorkflow::class);
            $reason = ReplayDiff::REASON_SHAPE_MISMATCH;
        } elseif ($case === 'activity-drift') {
            foreach ([1, 2] as $index) {
                $bundle['history_events'][$index]['payload']['activity_type'] = 'ChangedActivity';
            }
            $reason = ReplayDiff::REASON_SHAPE_MISMATCH;
        } elseif ($case === 'timer-drift') {
            $bundle = $this->bundle('timer', true);
            foreach ([
                3 => 'ActivityScheduled',
                4 => 'ActivityCompleted',
            ] as $index => $type) {
                $bundle['history_events'][$index]['type'] = $type;
                $bundle['history_events'][$index]['payload']['activity_type'] = TestQueryReplayGuardActivity::class;
            }
            $reason = ReplayDiff::REASON_SHAPE_MISMATCH;
        } else {
            $payload = [
                'sequence' => 1,
                'change_id' => 'legacy-upgrade',
                'version' => 1,
            ];
            if ($case === 'missing-change-id') {
                unset($payload['change_id']);
            } elseif ($case === 'wrong-change-id') {
                $payload['change_id'] = 'other-change';
            } elseif ($case === 'non-integer-version') {
                $payload['version'] = '1';
            } elseif ($case === 'unsupported-version') {
                $payload['version'] = 2;
            } elseif ($case === 'marker-and-activity') {
                $reason = ReplayDiff::REASON_SHAPE_MISMATCH;
            }
            if ($case !== 'marker-and-activity') {
                foreach ($bundle['history_events'] as &$event) {
                    if (isset($event['payload']['sequence'])) {
                        ++$event['payload']['sequence'];
                    }
                }
                unset($event);
            }
            array_splice($bundle['history_events'], 1, 0, [[
                'id' => 'marker-1',
                'sequence' => 2,
                'type' => 'VersionMarkerRecorded',
                'payload' => $payload,
                'recorded_at' => '2026-10-01T12:00:01+00:00',
            ]]);
            foreach ($bundle['history_events'] as $index => &$event) {
                $event['sequence'] = $index + 1;
            }
            unset($event);
            $bundle['workflow']['last_history_sequence'] = count($bundle['history_events']);
        }
        $before = $bundle;
        $report = (new ReplayDiff())->diffExport($bundle);

        $this->assertSame($reason, $report['reason'], json_encode($report));
        $this->assertSame(
            $reason === ReplayDiff::REASON_SHAPE_MISMATCH ? ReplayDiff::STATUS_DRIFTED : ReplayDiff::STATUS_FAILED,
            $report['status'],
        );
        $this->assertSame($before, $bundle);
    }

    public static function invalidHistories(): array
    {
        return array_combine(
            $cases = [
                'current-definition', 'activity-drift', 'timer-drift', 'missing-change-id',
                'wrong-change-id', 'non-integer-version', 'unsupported-version', 'marker-and-activity',
            ],
            array_map(static fn (string $case): array => [$case], $cases),
        );
    }

    private function bundle(string $patchBefore, bool $completed): array
    {
        $fixture = json_decode(file_get_contents(
            __DIR__ . '/../../Fixtures/V2/ReplayRegression/legacy-version-shared-step-position.json'
        ), true, flags: JSON_THROW_ON_ERROR);
        $events = $fixture['history'];
        if (! $completed) {
            array_pop($events);
        }

        return [
            'schema' => HistoryExport::SCHEMA,
            'schema_version' => HistoryExport::SCHEMA_VERSION,
            'workflow' => [
                'instance_id' => 'legacy-instance',
                'run_id' => 'legacy-run',
                'workflow_type' => $fixture['workflow']['type'],
                'workflow_class' => $fixture['workflow']['type'],
                'status' => $completed ? 'completed' : 'waiting',
                'last_history_sequence' => count($events),
            ],
            'payloads' => [
                'codec' => 'avro',
                'arguments' => [
                    'available' => true,
                    'data' => Serializer::serializeWithCodec('avro', [$patchBefore]),
                ],
                'output' => [
                    'available' => false,
                ],
            ],
            'history_events' => array_map(static fn (array $event): array => [
                'id' => 'event-' . $event['sequence'],
                'sequence' => $event['sequence'],
                'type' => $event['event_type'],
                'payload' => $event['payload'],
                'recorded_at' => $event['recorded_at'],
            ], $events),
        ];
    }
}
