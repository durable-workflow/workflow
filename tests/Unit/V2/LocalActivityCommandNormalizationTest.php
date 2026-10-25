<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\NonDatabaseTestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\WorkflowCommandNormalizer;

final class LocalActivityCommandNormalizationTest extends NonDatabaseTestCase
{
    #[DataProvider('malformedPolicyObjects')]
    public function testRetryPolicyRequiresAnObject(mixed $value): void
    {
        $command = $this->completedCommand();
        $command['retry_policy'] = $value;

        $this->assertRejected(
            $command,
            'retry_policy',
            'Workflow task command field [retry_policy] must be an object when provided.',
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedPolicyObjects(): iterable
    {
        yield 'string policy' => ['retry'];
        yield 'boolean policy' => [true];
        yield 'integer policy' => [0];
    }

    /**
     * @param array<string, mixed> $policy
     */
    #[DataProvider('malformedRetryPolicies')]
    public function testMalformedRetryPolicyNamesTheBrokenField(array $policy, string $field, string $message): void
    {
        $command = $this->completedCommand();
        $command['retry_policy'] = $policy;

        $this->assertRejected($command, $field, $message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function malformedRetryPolicies(): iterable
    {
        foreach ([0, -1, '2', true, null] as $index => $value) {
            yield 'invalid attempt limit ' . $index => [[
                'max_attempts' => $value,
            ], 'retry_policy.max_attempts', 'Local activity retry policy max_attempts must be a positive integer when provided.'];
        }

        foreach (['0', true, null] as $index => $value) {
            yield 'backoff is not a list ' . $index => [[
                'max_attempts' => 3,
                'backoff_seconds' => $value,
            ], 'retry_policy.backoff_seconds', 'Local activity retry policy backoff_seconds must be a list of non-negative integers.'];
        }

        foreach (['0', -1, true, 0.5, null] as $index => $value) {
            yield 'invalid backoff entry ' . $index => [[
                'max_attempts' => 3,
                'backoff_seconds' => [0, $value],
            ], 'retry_policy.backoff_seconds.1', 'Local activity retry policy backoff_seconds entries must be non-negative integers.'];
        }

        foreach (['Denied', true, null] as $index => $value) {
            yield 'error types are not a list ' . $index => [[
                'non_retryable_error_types' => $value,
            ], 'retry_policy.non_retryable_error_types', 'Local activity retry policy non_retryable_error_types must be a list of strings.'];
        }

        foreach ([' ', 7, [], null] as $index => $value) {
            yield 'invalid error type entry ' . $index => [[
                'non_retryable_error_types' => ['Denied', $value],
            ], 'retry_policy.non_retryable_error_types.1', 'Local activity retry policy non_retryable_error_types entries must be non-empty strings.'];
        }

        yield 'associative backoff list' => [[
            'max_attempts' => 2,
            'backoff_seconds' => [
                'first' => 0,
            ],
        ], 'retry_policy.backoff_seconds', 'Local activity retry policy backoff_seconds must be an ordered list.'];

        yield 'sparse error types list' => [[
            'non_retryable_error_types' => [
                1 => 'Denied',
            ],
        ], 'retry_policy.non_retryable_error_types', 'Local activity retry policy non_retryable_error_types must be an ordered list.'];

        yield 'duplicate error types' => [[
            'non_retryable_error_types' => ['Denied', 'Denied'],
        ], 'retry_policy.non_retryable_error_types', 'Local activity retry policy non_retryable_error_types must not contain duplicates.'];

        foreach ([
            'one attempt has no retries' => [
                'max_attempts' => 1,
                'backoff_seconds' => [0],
            ],
            'two attempts have only one retry' => [
                'max_attempts' => 2,
                'backoff_seconds' => [0, 1],
            ],
            'omitted limit defaults to one attempt' => [
                'backoff_seconds' => [0],
            ],
        ] as $name => $policy) {
            yield $name => [
                $policy,
                'retry_policy.backoff_seconds',
                'Local activity retry backoff entries must not exceed the number of possible retries.',
            ];
        }
    }

    #[DataProvider('malformedAttemptStrings')]
    public function testMalformedAttemptMetadataCannotBeSilentlyDiscarded(string $field, mixed $value): void
    {
        $command = $this->completedCommand();
        $command['attempts'][0][$field] = $value;

        $this->assertRejected(
            $command,
            'attempts.0.' . $field,
            sprintf('Local activity attempt field [%s] must be a non-empty string.', $field),
        );
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function malformedAttemptStrings(): iterable
    {
        foreach (['attempt_id', 'message', 'exception_type', 'timeout_kind', 'retry_reason'] as $field) {
            yield $field . ' is blank' => [$field, ' '];
            yield $field . ' is numeric' => [$field, 7];
        }
    }

    #[DataProvider('nonBooleanRetryability')]
    public function testAttemptRetryabilityRequiresABoolean(mixed $value): void
    {
        $command = $this->completedCommand();
        $command['attempts'][0]['non_retryable'] = $value;

        $this->assertRejected(
            $command,
            'attempts.0.non_retryable',
            'Local activity attempt non_retryable must be boolean when provided.',
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonBooleanRetryability(): iterable
    {
        yield 'explicit null' => [null];
        yield 'integer zero' => [0];
        yield 'string false' => ['false'];
        yield 'empty list' => [[]];
    }

    #[DataProvider('timeoutRetryReasons')]
    public function testTimeoutRetryReportPreservesOrderedAttemptsAndTypedMetadata(string $reason): void
    {
        $command = [
            'type' => 'record_local_activity',
            'activity_type' => ' charge-card ',
            'outcome' => 'failed',
            'message' => ' terminal failure ',
            'exception_type' => ' PaymentRejected ',
            'non_retryable' => false,
            'retry_policy' => [
                'max_attempts' => 2,
                'backoff_seconds' => [0],
                'non_retryable_error_types' => [' PaymentRejected ', 'InvalidAccount'],
            ],
            'attempts' => [
                [
                    'attempt_id' => ' first-attempt ',
                    'attempt_number' => 1,
                    'outcome' => 'timed_out',
                    'duration_ms' => 0,
                    'message' => ' heartbeat expired ',
                    'timeout_kind' => ' heartbeat ',
                    'non_retryable' => false,
                    'retry_reason' => ' ' . $reason . ' ',
                    'backoff_seconds' => 0,
                    'heartbeats' => [[
                        'elapsed_ms' => 0,
                        'details' => [
                            'charged' => false,
                            'balance' => 0,
                        ],
                    ]],
                ],
                [
                    'attempt_id' => ' second-attempt ',
                    'attempt_number' => 2,
                    'outcome' => 'failed',
                    'duration_ms' => 5,
                    'message' => ' payment refused ',
                    'exception_type' => ' PaymentRejected ',
                    'non_retryable' => false,
                    'heartbeats' => [],
                ],
            ],
        ];
        $original = $command;
        $expected = [
            'type' => 'record_local_activity',
            'activity_type' => 'charge-card',
            'outcome' => 'failed',
            'message' => 'terminal failure',
            'exception_type' => 'PaymentRejected',
            'non_retryable' => false,
            'attempts' => [
                [
                    'attempt_id' => 'first-attempt',
                    'attempt_number' => 1,
                    'outcome' => 'timed_out',
                    'duration_ms' => 0,
                    'message' => 'heartbeat expired',
                    'non_retryable' => false,
                    'timeout_kind' => 'heartbeat',
                    'retry_reason' => $reason,
                    'backoff_seconds' => 0,
                    'heartbeats' => [[
                        'elapsed_ms' => 0,
                        'details' => [
                            'charged' => false,
                            'balance' => 0,
                        ],
                    ]],
                ],
                [
                    'attempt_id' => 'second-attempt',
                    'attempt_number' => 2,
                    'outcome' => 'failed',
                    'duration_ms' => 5,
                    'message' => 'payment refused',
                    'exception_type' => 'PaymentRejected',
                    'non_retryable' => false,
                    'heartbeats' => [],
                ],
            ],
            'retry_policy' => [
                'max_attempts' => 2,
                'backoff_seconds' => [0],
                'non_retryable_error_types' => ['PaymentRejected', 'InvalidAccount'],
            ],
            'execution_mode' => 'local',
        ];

        foreach ([1, 2] as $_) {
            $this->assertSame([$expected], WorkflowCommandNormalizer::normalize([$command], '1.19'));
            $this->assertSame($original, $command);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function timeoutRetryReasons(): iterable
    {
        yield 'timeout retry' => ['timeout'];
        yield 'cold replay retry' => ['cold_replay'];
    }

    public function testRetainedProtocolSynthesizesOneTypedTerminalTimeoutWithoutInventingWorkerIdentity(): void
    {
        $command = [
            'type' => 'record_local_activity',
            'activity_type' => 'charge-card',
            'outcome' => 'timed_out',
            'message' => ' heartbeat expired ',
            'exception_type' => ' HeartbeatExpired ',
            'non_retryable' => false,
            'timeout_kind' => ' heartbeat ',
        ];
        $original = $command;
        $expected = [[
            'type' => 'record_local_activity',
            'activity_type' => 'charge-card',
            'outcome' => 'timed_out',
            'message' => 'heartbeat expired',
            'exception_type' => 'HeartbeatExpired',
            'non_retryable' => false,
            'timeout_kind' => 'heartbeat',
            'attempts' => [[
                'attempt_number' => 1,
                'outcome' => 'timed_out',
                'message' => 'heartbeat expired',
                'exception_type' => 'HeartbeatExpired',
                'non_retryable' => false,
                'timeout_kind' => 'heartbeat',
                'heartbeats' => [],
            ]],
            'execution_mode' => 'local',
        ]];

        foreach ([1, 2] as $_) {
            $this->assertSame($expected, WorkflowCommandNormalizer::normalize([$command], '1.18'));
            $this->assertSame($original, $command);
        }

        $this->assertRejected($command, 'attempts', 'Local activity attempts must be a non-empty ordered list.');
    }

    public function testExplicitNullOptionalAttemptStringsAreOmittedWithoutChangingTerminalEvidence(): void
    {
        $command = $this->completedCommand();
        $command['attempts'][0] += array_fill_keys(
            ['attempt_id', 'message', 'exception_type', 'timeout_kind', 'retry_reason'],
            null,
        );
        $original = $command;

        $normalized = WorkflowCommandNormalizer::normalize([$command], '1.19');

        $this->assertSame([[
            'attempt_number' => 1,
            'outcome' => 'completed',
            'heartbeats' => [],
        ]], $normalized[0]['attempts']);
        $this->assertSame($command['result'], $normalized[0]['result']);
        $this->assertSame('completed', $normalized[0]['outcome']);
        $this->assertSame($original, $command);
    }

    /**
     * Workflow #930: the timing fields recording walks back from completion
     * are bounded, so no report can drive timestamp arithmetic out of range.
     */
    #[DataProvider('timingFields')]
    public function testTimingFieldsAreBoundedForTimestampArithmetic(string $field, int $maximum, string $message): void
    {
        $first = $this->retriedCommand($field, $maximum)['attempts'][0];
        $this->assertSame($maximum, $field === 'elapsed_ms' ? $first['heartbeats'][0]['elapsed_ms'] : $first[$field]);
        foreach ([$maximum + 1, PHP_INT_MAX] as $value) {
            try {
                $this->retriedCommand($field, $value);
            } catch (ValidationException $error) {
                $path = $field === 'elapsed_ms' ? 'commands.0.attempts.0.heartbeats.0.elapsed_ms' : 'commands.0.attempts.0.' . $field;
                $this->assertSame([
                    $path => [$message],
                ], $error->errors());

                continue;
            }

            $this->fail(sprintf('%s %d was accepted.', $field, $value));
        }
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function timingFields(): iterable
    {
        yield 'attempt duration' => ['duration_ms', WorkflowCommandNormalizer::MAX_LOCAL_ACTIVITY_DURATION_MS,
            'Local activity attempt duration_ms must not exceed 604800000.'];
        yield 'heartbeat offset' => ['elapsed_ms', WorkflowCommandNormalizer::MAX_LOCAL_ACTIVITY_DURATION_MS,
            'Local activity heartbeat elapsed_ms must not exceed 604800000.'];
        yield 'retry backoff' => ['backoff_seconds', WorkflowCommandNormalizer::MAX_LOCAL_ACTIVITY_BACKOFF_SECONDS,
            'Local activity attempt backoff_seconds must not exceed 86400.'];
    }

    public function testACompletionRecordsAtMostOneHistoryTransactionOfEvents(): void
    {
        $this->assertSame(5000, \Workflow\V2\Support\StructuralLimits::historyTransactionSizeLimit());
        $normalize = static fn (array $commands): array => WorkflowCommandNormalizer::normalize($commands, '1.19');
        // Four commands of one attempt and 1,000 heartbeats record 1,003
        // events each; a fifth of one attempt records 3 plus its heartbeats.
        $commands = array_fill(0, 4, $this->heartbeatCommand(1000));
        $this->assertSame(1003, WorkflowCommandNormalizer::localActivityHistoryEvents($this->heartbeatCommand(1000)));
        $this->assertSame([], WorkflowCommandNormalizer::completionBudgetErrors(
            $normalize([...$commands, $this->heartbeatCommand(985)]),
        ));
        $this->assertSame([
            'This completion would record more than 5000 history events; one workflow task completion may record at most 5000.',
        ], WorkflowCommandNormalizer::completionBudgetErrors($normalize([...$commands, $this->heartbeatCommand(986)])));
        // Every other command counts as one event, a lower bound.
        $this->assertSame(7, WorkflowCommandNormalizer::localActivityHistoryEvents([
            'attempts' => [[
                'heartbeats' => [[], []],
            ], [
                'heartbeats' => [],
            ]],
        ]));
        config([
            'workflows.v2.structural_limits.history_transaction_size' => 4,
        ]);
        $this->assertSame([], WorkflowCommandNormalizer::completionBudgetErrors(
            $normalize([[
                'type' => 'complete_workflow',
            ], $this->heartbeatCommand(0)]),
        ));
        $this->assertCount(1, WorkflowCommandNormalizer::completionBudgetErrors(
            $normalize([[
                'type' => 'complete_workflow',
            ], $this->heartbeatCommand(1)]),
        ));
        // A disabled limit (0) refuses nothing.
        config([
            'workflows.v2.structural_limits.history_transaction_size' => 0,
        ]);
        $this->assertSame(
            [],
            WorkflowCommandNormalizer::completionBudgetErrors($normalize([...$commands, $commands[0]]))
        );
    }

    /**
     * A failed first attempt retried into a completed second, with the timing
     * field under test set on the first.
     *
     * @return array<string, mixed>
     */
    private function retriedCommand(string $field, int $value): array
    {
        $first = [
            'attempt_number' => 1,
            'outcome' => 'failed',
            'message' => 'declined',
            'retry_reason' => 'failure',
            'backoff_seconds' => 0,
            'heartbeats' => [],
        ];
        if ($field === 'elapsed_ms') {
            $first['heartbeats'] = [[
                'elapsed_ms' => $value,
            ]];
        } else {
            $first[$field] = $value;
        }

        return WorkflowCommandNormalizer::normalize([[
            ...$this->completedCommand(),
            'retry_policy' => [
                'max_attempts' => 2,
            ],
            'attempts' => [
                $first, [
                    'attempt_number' => 2,
                    'outcome' => 'completed',
                    'heartbeats' => [],
                ]],
        ]], '1.19')[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function heartbeatCommand(int $heartbeats): array
    {
        $command = $this->completedCommand();
        $command['attempts'][0]['heartbeats'] = array_map(
            static fn (int $elapsed): array => [
                'elapsed_ms' => $elapsed,
            ],
            $heartbeats === 0 ? [] : range(0, $heartbeats - 1),
        );

        return $command;
    }

    /**
     * @return array<string, mixed>
     */
    private function completedCommand(): array
    {
        return [
            'type' => 'record_local_activity',
            'activity_type' => 'charge-card',
            'result' => Serializer::serializeWithCodec('avro', 'charged'),
            'outcome' => 'completed',
            'attempts' => [[
                'attempt_number' => 1,
                'outcome' => 'completed',
                'heartbeats' => [],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $command
     */
    private function assertRejected(array $command, string $field, string $message): void
    {
        $original = $command;
        foreach ([1, 2] as $_) {
            try {
                WorkflowCommandNormalizer::normalize([[
                    'type' => 'complete_workflow',
                ], $command], '1.19');
            } catch (ValidationException $error) {
                $this->assertSame([
                    'commands.1.' . $field => [$message],
                ], $error->errors());
                $this->assertSame($original, $command);

                continue;
            }

            $this->fail('Malformed local-activity report was accepted.');
        }
    }
}
