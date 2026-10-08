<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\NonDatabaseTestCase;
use Workflow\V2\Support\WorkerSessionOptions;
use Workflow\V2\Support\WorkflowCommandNormalizer;

final class WorkerSessionCommandNormalizationTest extends NonDatabaseTestCase
{
    #[DataProvider('absentSessions')]
    public function testOrdinaryActivitiesKeepTheirCommandShape(array $metadata): void
    {
        $commands = [[
            'type' => 'schedule_activity',
            'activity_type' => ' RenderFrames ',
            ...$metadata,
        ]];
        $original = $commands;
        self::assertSame([[
            'type' => 'schedule_activity',
            'activity_type' => 'RenderFrames',
        ]], WorkflowCommandNormalizer::normalize($commands));
        self::assertSame($original, $commands);
    }

    public static function absentSessions(): iterable
    {
        yield 'omitted' => [[]];
        yield 'explicit null' => [[
            'worker_session' => null,
        ]];
    }

    #[DataProvider('acceptedSessions')]
    public function testSessionNormalizationPreservesTypedAffinityAndIndependentRouting(
        array $session,
        array $expected,
    ): void {
        $commands = [[
            'type' => 'schedule_activity',
            'activity_type' => ' RenderFrames ',
            'connection' => ' ordinary-connection ',
            'queue' => ' ordinary-queue ',
            'worker_session' => $session,
        ]];
        $original = $commands;
        $normalized = WorkflowCommandNormalizer::normalize($commands);
        self::assertSame([[
            'type' => 'schedule_activity',
            'activity_type' => 'RenderFrames',
            'connection' => 'ordinary-connection',
            'queue' => 'ordinary-queue',
            'worker_session' => $expected,
        ]], $normalized);
        self::assertSame($normalized, WorkflowCommandNormalizer::normalize($normalized));
        self::assertSame($original, $commands);
    }

    public static function acceptedSessions(): iterable
    {
        yield 'only trimmed identity' => [[
            'session_id' => ' gpu-render ',
        ], [
            'session_id' => 'gpu-render',
        ]];
        foreach ([true, false] as $allowed) {
            yield 'explicit lifecycle flags ' . ($allowed ? 'enabled' : 'disabled') => [
                [
                    'session_id' => ' gpu-render ',
                    'connection' => ' gpu-connection ',
                    'queue' => ' gpu-queue ',
                    'lease_seconds' => 1,
                    'ttl_seconds' => 1800,
                    'max_concurrent_activities' => 2,
                    'create_if_missing' => $allowed,
                    'allow_reacquire_after_failure' => $allowed,
                    'requirements' => [' gpu:nvidia-l4 ', 'fs:/mnt/models', 'gpu:nvidia-l4'],
                ],
                [
                    'session_id' => 'gpu-render',
                    'connection' => 'gpu-connection',
                    'queue' => 'gpu-queue',
                    'lease_seconds' => 1,
                    'ttl_seconds' => 1800,
                    'max_concurrent_activities' => 2,
                    'create_if_missing' => $allowed,
                    'allow_reacquire_after_failure' => $allowed,
                    'requirements' => ['gpu:nvidia-l4', 'fs:/mnt/models'],
                ],
            ];
        }
        yield 'nullable options are omitted' => [
            [
                'session_id' => 'gpu-render',
                'connection' => null,
                'queue' => null,
                'lease_seconds' => null,
                'ttl_seconds' => null,
                'max_concurrent_activities' => null,
                'create_if_missing' => null,
                'allow_reacquire_after_failure' => null,
                'requirements' => null,
            ], [
                'session_id' => 'gpu-render',
            ],
        ];
        yield 'empty requirements do not add an affinity constraint' => [
            [
                'session_id' => 'gpu-render',
                'requirements' => [],
            ], [
                'session_id' => 'gpu-render',
            ],
        ];
        yield 'repeated requirements keep first occurrence order' => [
            [
                'session_id' => 'gpu-render',
                'requirements' => [' zone:west ', 'gpu:l4', 'zone:west', ' fs:/mnt/models ', 'gpu:l4'],
            ], [
                'session_id' => 'gpu-render',
                'requirements' => ['zone:west', 'gpu:l4', 'fs:/mnt/models'],
            ],
        ];
    }

    #[DataProvider('invalidSessions')]
    public function testMalformedSessionRejectsTheWholeCommandBatchWithIndexedDiagnostics(
        mixed $session,
        string $field,
        string $message,
    ): void {
        $commands = [
            [
                'type' => 'schedule_activity',
                'activity_type' => 'PrepareFrames',
            ],
            [
                'type' => 'schedule_activity',
                'activity_type' => 'RenderFrames',
                'worker_session' => $session,
            ],
            [
                'type' => 'complete_workflow',
            ],
        ];
        $original = $commands;
        for ($delivery = 0; $delivery < 2; ++$delivery) {
            try {
                WorkflowCommandNormalizer::normalize($commands);
                self::fail('Malformed worker-session metadata must reject the command batch.');
            } catch (ValidationException $error) {
                self::assertSame([
                    'commands.1.worker_session' . $field => [$message],
                ], $error->errors());
            }
            self::assertSame($original, $commands);
        }
    }

    public function testAuthoringSnapshotRetainsEveryTypedFieldInTheWorkerCommand(): void
    {
        $snapshot = (new WorkerSessionOptions(
            sessionId: ' gpu-render ',
            connection: ' gpu-connection ',
            queue: ' gpu-queue ',
            requirements: [' gpu:l4 ', 'gpu:l4', 'fs:/mnt/models'],
            leaseSeconds: 120,
            ttlSeconds: 600,
            maxConcurrentActivities: 1,
            createIfMissing: false,
            allowReacquireAfterFailure: false,
        ))->toSnapshot();
        $commands = [[
            'type' => 'schedule_activity',
            'activity_type' => 'RenderFrames',
            'worker_session' => $snapshot,
        ]];
        $normalized = WorkflowCommandNormalizer::normalize($commands)[0]['worker_session'];
        self::assertCount(count($snapshot), $normalized);
        foreach ($snapshot as $field => $value) {
            self::assertSame($value, $normalized[$field], $field);
        }
        self::assertSame($snapshot, $commands[0]['worker_session']);
    }

    public function testAllSessionFieldErrorsRetainTheirCommandAndRequirementPositions(): void
    {
        $commands = [
            [
                'type' => 'complete_workflow',
            ],
            [
                'type' => 'schedule_activity',
                'activity_type' => 'RenderFrames',
                'worker_session' => [
                    'session_id' => 'gpu-render',
                    'connection' => ' ',
                    'queue' => [],
                    'lease_seconds' => 0,
                    'ttl_seconds' => '1800',
                    'max_concurrent_activities' => false,
                    'create_if_missing' => 1,
                    'allow_reacquire_after_failure' => 'false',
                    'requirements' => [' ', 'gpu:l4', 7, 'fs:/mnt/models'],
                ],
            ],
        ];
        $original = $commands;
        try {
            WorkflowCommandNormalizer::normalize($commands);
            self::fail('All malformed session fields must be reported before accepting the batch.');
        } catch (ValidationException $error) {
            self::assertSame([
                'commands.1.worker_session.connection' => [
                    'Worker session field [connection] must be a non-empty string when provided.',
                ],
                'commands.1.worker_session.queue' => [
                    'Worker session field [queue] must be a non-empty string when provided.',
                ],
                'commands.1.worker_session.lease_seconds' => [
                    'Worker session field [lease_seconds] must be a positive integer when provided.',
                ],
                'commands.1.worker_session.ttl_seconds' => [
                    'Worker session field [ttl_seconds] must be a positive integer when provided.',
                ],
                'commands.1.worker_session.max_concurrent_activities' => [
                    'Worker session field [max_concurrent_activities] must be a positive integer when provided.',
                ],
                'commands.1.worker_session.create_if_missing' => [
                    'Worker session field [create_if_missing] must be boolean when provided.',
                ],
                'commands.1.worker_session.allow_reacquire_after_failure' => [
                    'Worker session field [allow_reacquire_after_failure] must be boolean when provided.',
                ],
                'commands.1.worker_session.requirements.0' => [
                    'Worker session requirements must be non-empty strings.',
                ],
                'commands.1.worker_session.requirements.2' => [
                    'Worker session requirements must be non-empty strings.',
                ],
            ], $error->errors());
        }
        self::assertSame($original, $commands);
    }

    public static function invalidSessions(): iterable
    {
        foreach (['session', false, 7, 1.5] as $position => $session) {
            yield 'session must be an object ' . $position => [
                $session, '', 'Workflow task command field [worker_session] must be an object when provided.',
            ];
        }
        yield 'missing session identity' => [[],
            '.session_id',
            'Worker session commands require a non-empty session_id.',
        ];
        foreach ([null, '', ' ', 7, false, []] as $position => $identity) {
            yield 'invalid session identity ' . $position => [
                [
                    'session_id' => $identity,
                ], '.session_id', 'Worker session commands require a non-empty session_id.',
            ];
        }
        foreach (['connection', 'queue'] as $field) {
            foreach (['', ' ', 7, []] as $position => $value) {
                yield $field . ' rejects non-string or blank value ' . $position => [
                    [
                        'session_id' => 'gpu-render',
                        $field => $value,
                    ], '.' . $field,
                    'Worker session field [' . $field . '] must be a non-empty string when provided.',
                ];
            }
        }
        foreach (['lease_seconds', 'ttl_seconds', 'max_concurrent_activities'] as $field) {
            foreach ([0, -1, '1', 1.5, true, []] as $position => $value) {
                yield $field . ' requires a positive integer ' . $position => [
                    [
                        'session_id' => 'gpu-render',
                        $field => $value,
                    ], '.' . $field,
                    'Worker session field [' . $field . '] must be a positive integer when provided.',
                ];
            }
        }
        foreach (['create_if_missing', 'allow_reacquire_after_failure'] as $field) {
            foreach ([0, 1, 'false', []] as $position => $value) {
                yield $field . ' requires an actual boolean ' . $position => [
                    [
                        'session_id' => 'gpu-render',
                        $field => $value,
                    ], '.' . $field,
                    'Worker session field [' . $field . '] must be boolean when provided.',
                ];
            }
        }
        foreach (['gpu:l4', false, 7] as $position => $requirements) {
            yield 'requirements must be a collection ' . $position => [
                [
                    'session_id' => 'gpu-render',
                    'requirements' => $requirements,
                ], '.requirements', 'Worker session requirements must be a list of non-empty strings.',
            ];
        }
        foreach (['', ' ', 7, false, null, []] as $position => $requirement) {
            yield 'requirements entry must be a non-empty string ' . $position => [
                [
                    'session_id' => 'gpu-render',
                    'requirements' => ['gpu:l4', $requirement, 'fs:/mnt/models'],
                ], '.requirements.1', 'Worker session requirements must be non-empty strings.',
            ];
        }
    }
}
