<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\ExternalPayloadReference;
use Workflow\V2\Support\PreparedLocalActivityReport;

final class PreparedLocalActivityReportTest extends TestCase
{
    public function testFailedReportsAllowOnlyTypedApplicationFailureInput(): void
    {
        foreach ([
            [
                'outcome' => 'failed',
                'message' => '',
            ],
            [
                'outcome' => 'failed',
                'message' => 'failure',
                'non_retryable' => false,
            ],
            [
                'outcome' => 'failed',
                'message' => 'échec',
                'exception_type' => 'RemoteFailure',
            ],
            [
                'outcome' => 'failed',
                'message' => 'failure',
                'exception_type' => str_repeat('a', 255),
                'non_retryable' => true,
                'next_retry_delay_seconds' => 0,
            ],
            [
                'outcome' => 'failed',
                'message' => 'failure',
                'next_retry_delay_seconds' => 9007199254740991,
            ],
            [
                'outcome' => 'timed_out',
            ],
        ] as $report) {
            self::assertNull(PreparedLocalActivityReport::refusal($report));
        }
    }

    public function testMalformedOverrideWinsOverOtherReportErrors(): void
    {
        foreach ([null, false, true, -1, '1', 1.0, 9007199254740992] as $delay) {
            foreach ([[], [
                'outcome' => 'failed',
            ], [
                'outcome' => 'failed',
                'message' => false,
                'extra' => 1,
            ]] as $base) {
                self::assertSame(
                    PreparedLocalActivityReport::INVALID_OVERRIDE,
                    PreparedLocalActivityReport::refusal([
                        ...$base,
                        'next_retry_delay_seconds' => $delay,
                    ])
                );
            }
        }
        foreach (['completed', 'timed_out', 'other', null] as $outcome) {
            self::assertSame(
                PreparedLocalActivityReport::INVALID_OVERRIDE,
                PreparedLocalActivityReport::refusal([
                    'outcome' => $outcome,
                    'next_retry_delay_seconds' => 0,
                ])
            );
        }
        foreach ([[
            'outcome' => 'failed',
        ], [
            'outcome' => 'failed',
            'message' => false,
        ],
            [
                'outcome' => 'failed',
                'message' => '',
                'extra' => 1,
            ]] as $report) {
            self::assertSame(
                PreparedLocalActivityReport::INVALID_REPORT,
                PreparedLocalActivityReport::refusal([
                    ...$report,
                    'next_retry_delay_seconds' => 0,
                ])
            );
        }
    }

    public function testNoOverrideReportStillHasAClosedVersionTwoGrammar(): void
    {
        $invalid = [[], [
            'outcome' => 'unknown',
        ], [
            'outcome' => false,
        ], [
            'outcome' => 'failed',
        ],
            [
                'outcome' => 'failed',
                'message' => null,
            ], [
                'outcome' => 'failed',
                'message' => 1,
            ],
            [
                'outcome' => 'failed',
                'message' => "\xff",
            ]];
        foreach ([null, '', ' ', false, 1, str_repeat('a', 256), "\xff"] as $type) {
            $invalid[] = [
                'outcome' => 'failed',
                'message' => '',
                'exception_type' => $type,
            ];
        }
        foreach ([null, 0, 'false'] as $flag) {
            $invalid[] = [
                'outcome' => 'failed',
                'message' => '',
                'non_retryable' => $flag,
            ];
        }
        foreach (['retry_disposition', 'backoff_seconds', 'retry_backoff_seconds', 'retry_available_at',
            'failure_time', 'original_schedule_to_close_deadline_at', 'snapshot_version', 'extra'] as $field) {
            $invalid[] = [
                'outcome' => 'failed',
                'message' => '',
                $field => 0,
            ];
            $invalid[] = [
                'outcome' => 'timed_out',
                $field => 0,
            ];
        }
        $invalid[] = [
            'outcome' => 'timed_out',
            'timeout_kind' => 'heartbeat',
        ];
        $invalid[] = [
            'outcome' => 'timed_out',
            'message' => 'worker selected timeout',
        ];
        foreach ($invalid as $index => $report) {
            self::assertSame(
                PreparedLocalActivityReport::INVALID_REPORT,
                PreparedLocalActivityReport::refusal($report),
                'invalid report ' . $index
            );
        }
    }

    public function testCompletedPreflightChecksStructureWithoutResolvingTheReference(): void
    {
        $reference = $this->reference();
        foreach ([
            [
                'outcome' => 'completed',
                'result' => 'opaque-avro',
                'payload_codec' => 'avro',
            ],
            [
                'outcome' => 'completed',
                'result' => [
                    'blob' => 'opaque-avro',
                    'codec' => 'avro',
                ],
            ],
            [
                'outcome' => 'completed',
                'result' => [
                    'codec' => 'avro',
                    'blob' => 'opaque-avro',
                ],
                'payload_codec' => 'avro',
            ],
            [
                'outcome' => 'completed',
                'result' => [
                    'codec' => 'avro',
                    'external_storage' => $reference,
                ],
            ],
        ] as $report) {
            self::assertNull(PreparedLocalActivityReport::refusal($report));
        }
        // This reference has no installed storage driver and no corresponding
        // bytes. Shape admission cannot claim fetched or decoded payload proof.
        self::assertSame('memory://unresolved-result', $reference['uri']);
    }

    public function testMalformedCompletedShapesCannotReachPayloadResolution(): void
    {
        $invalid = [[
            'outcome' => 'completed',
        ], [
            'outcome' => 'completed',
            'result' => 'opaque-avro',
        ],
            [
                'outcome' => 'completed',
                'result' => '',
                'payload_codec' => 'avro',
            ],
            [
                'outcome' => 'completed',
                'result' => 'opaque-avro',
                'payload_codec' => null,
            ],
            [
                'outcome' => 'completed',
                'result' => 'opaque-avro',
                'payload_codec' => 'json',
            ],
            [
                'outcome' => 'completed',
                'result' => [
                    'codec' => 'avro',
                    'blob' => 'opaque-avro',
                ],
                'extra' => 0,
            ]];
        foreach ([null, false, 1, [], [
            'codec' => 'avro',
        ], [
            'codec' => 'json',
            'blob' => 'bytes',
        ],
            [
                'codec' => 'avro',
                'blob' => '',
            ], [
                'codec' => 'avro',
                'blob' => false,
            ],
            [
                'codec' => 'avro',
                'blob' => 'bytes',
                'extra' => 1,
            ],
            [
                'codec' => 'avro',
                'blob' => 'bytes',
                'external_storage' => $this->reference(),
            ],
            [
                'codec' => 'avro',
                'external_storage' => null,
            ]] as $result) {
            $invalid[] = [
                'outcome' => 'completed',
                'result' => $result,
            ];
        }
        foreach ($invalid as $index => $report) {
            self::assertSame(
                PreparedLocalActivityReport::INVALID_REPORT,
                PreparedLocalActivityReport::refusal($report),
                'completed shape ' . $index
            );
        }
    }

    public function testExternalReferenceMetadataUsesTheExistingPureValueObject(): void
    {
        $invalid = [];
        foreach (array_keys($this->reference()) as $field) {
            $reference = $this->reference();
            unset($reference[$field]);
            $invalid[] = $reference;
        }
        foreach ([[
            'schema' => 'unknown',
        ], [
            'uri' => '',
        ], [
            'uri' => false,
        ], [
            'sha256' => 'not-a-digest',
        ],
            [
                'sha256' => 1,
            ], [
                'size_bytes' => -1,
            ], [
                'size_bytes' => '1',
            ], [
                'size_bytes' => 1.0,
            ],
            [
                'codec' => null,
            ], [
                'codec' => '',
            ], [
                'codec' => 'json',
            ]] as $changes) {
            $invalid[] = array_replace($this->reference(), $changes);
        }
        foreach ($invalid as $index => $reference) {
            self::assertSame(
                PreparedLocalActivityReport::INVALID_REPORT,
                PreparedLocalActivityReport::refusal([
                    'outcome' => 'completed',
                    'result' => [
                        'codec' => 'avro',
                        'external_storage' => $reference,
                    ],
                ]),
                'reference ' . $index
            );
        }
    }

    private function reference(): array
    {
        return [
            'schema' => ExternalPayloadReference::SCHEMA,
            'uri' => 'memory://unresolved-result',
            'sha256' => str_repeat('a', 64),
            'size_bytes' => 12,
            'codec' => 'avro',
        ];
    }
}
