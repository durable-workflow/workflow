<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use ReflectionClass;
use Tests\TestCase;
use Workflow\Commands\V2WorkflowUpdatesConformanceCommand;

final class V2WorkflowUpdatesConformanceCommandTest extends TestCase
{
    public function testCommandEmitsWorkflowUpdatesShardShapeWithTypedMissingConnectionEvidence(): void
    {
        $reportPath = $this->ephemeralPath('workflow-updates-conformance-out');

        $this->artisan('workflow:v2:workflow-updates-conformance', [
            '--run-id' => 'php-updates-test',
            '--artifact-version' => [
                'server=0.2.542',
                'cli=0.1.82',
                'workflow=2.0.0-alpha.241',
                'sdk-python=0.4.92',
                'waterline=2.0.0-alpha.111',
            ],
            '--artifact-source' => [
                'server=docker_image',
                'cli=official_install_script',
                'workflow=packagist_package',
                'sdk-python=pypi_package',
                'waterline=packagist_package',
            ],
            '--output' => $reportPath,
        ])->assertFailed();

        $report = $this->readJson($reportPath);
        $scenarios = array_column($report['scenario_results'], null, 'scenario_id');

        $this->assertSame('durable-workflow.v2.workflow-update-runtime.result', $report['schema']);
        $this->assertSame('workflow-php-updates-shard', $report['coverage_scope']);
        $this->assertFalse($report['runner_blocked']);
        $this->assertSame('2.0.0-alpha.241', $report['artifact_versions']['workflow-php']);
        $this->assertSame('packagist_package', $report['php_client_worker_update_surface']['workflow_php_artifact_source']);
        $this->assertContains(
            'invalid_input_refusal',
            $report['php_client_worker_update_surface']['required_cells'],
        );
        $this->assertSame('pass', $scenarios['published_artifact_install_only']['status']);
        $this->assertSame('pass', $scenarios['declared_update_contract_visibility']['status']);
        $this->assertSame('fail', $scenarios['php_client_worker_update_surface']['status']);
        $this->assertSame(
            ['server-url', 'token'],
            $report['php_client_worker_update_surface']['unsupported_cells'][0]['missing_options'],
        );
        $this->assertContains(
            'approve',
            $scenarios['declared_update_contract_visibility']['observed_outputs']['declared_updates'],
        );
    }

    public function testExceptionCellRequiresExpectedReasonWhenServerProvidesOne(): void
    {
        $unexpected = $this->invokeConformanceCell('exceptionCell', [
            [
                'outcome' => 'exception',
                'exception' => [
                    'status' => 422,
                    'body' => [
                        'reason' => 'validation_failed',
                    ],
                ],
            ],
            ['unknown_update', 'update_not_found'],
        ]);

        $expected = $this->invokeConformanceCell('exceptionCell', [
            [
                'outcome' => 'exception',
                'exception' => [
                    'status' => 404,
                    'body' => [
                        'reason' => 'unknown_update',
                    ],
                ],
            ],
            ['unknown_update', 'update_not_found'],
        ]);

        $this->assertSame('fail', $unexpected['status']);
        $this->assertFalse($unexpected['evidence']['checks']['reason_accepted']);
        $this->assertSame('pass', $expected['status']);
        $this->assertTrue($expected['evidence']['checks']['reason_accepted']);
    }

    public function testDuplicateCellRequiresSameRequestAndUpdateId(): void
    {
        $original = [
            'outcome' => 'response',
            'request' => [
                'workflow_id' => 'workflow-1',
                'update_name' => 'approve',
                'request_id' => 'request-1',
            ],
            'response' => [
                'accepted' => true,
                'update_id' => 'update-1',
                'update_status' => 'accepted',
            ],
        ];
        $sameUpdate = [
            'outcome' => 'response',
            'request' => [
                'workflow_id' => 'workflow-1',
                'update_name' => 'approve',
                'request_id' => 'request-1',
            ],
            'response' => [
                'accepted' => true,
                'update_id' => 'update-1',
                'update_status' => 'completed',
            ],
        ];
        $differentUpdate = [
            'outcome' => 'response',
            'request' => [
                'workflow_id' => 'workflow-1',
                'update_name' => 'approve',
                'request_id' => 'request-1',
            ],
            'response' => [
                'accepted' => true,
                'update_id' => 'update-2',
                'update_status' => 'accepted',
            ],
        ];
        $differentRequest = [
            'outcome' => 'response',
            'request' => [
                'workflow_id' => 'workflow-1',
                'update_name' => 'approve',
                'request_id' => 'request-2',
            ],
            'response' => [
                'accepted' => true,
                'update_id' => 'update-1',
                'update_status' => 'accepted',
            ],
        ];

        $passing = $this->invokeConformanceCell('duplicateCell', [$original, $sameUpdate]);
        $newUpdate = $this->invokeConformanceCell('duplicateCell', [$original, $differentUpdate]);
        $newRequest = $this->invokeConformanceCell('duplicateCell', [$original, $differentRequest]);

        $this->assertSame('pass', $passing['status']);
        $this->assertTrue($passing['evidence']['checks']['same_update_id']);
        $this->assertSame('fail', $newUpdate['status']);
        $this->assertFalse($newUpdate['evidence']['checks']['same_update_id']);
        $this->assertSame('fail', $newRequest['status']);
        $this->assertFalse($newRequest['evidence']['checks']['same_request']);
    }

    public function testCompletedClientCellRequiresCompletedClientResponseAndHandlerCommand(): void
    {
        $passing = $this->invokeConformanceCell('completedClientCell', [
            [
                'outcome' => 'response',
                'response' => [
                    'update_status' => 'completed',
                    'wait_timed_out' => false,
                ],
            ],
            ['type' => 'complete_update'],
        ]);
        $timedOut = $this->invokeConformanceCell('completedClientCell', [
            [
                'outcome' => 'response',
                'response' => [
                    'update_status' => 'accepted',
                    'wait_timed_out' => true,
                ],
            ],
            ['type' => 'complete_update'],
        ]);

        $this->assertSame('pass', $passing['status']);
        $this->assertTrue($passing['evidence']['checks']['client_completed']);
        $this->assertTrue($passing['evidence']['checks']['handler_completed']);
        $this->assertSame('fail', $timedOut['status']);
        $this->assertFalse($timedOut['evidence']['checks']['client_completed']);
    }

    public function testFailedClientCellAcceptsTypedFailureEnvelopeAndHandlerFailure(): void
    {
        $passing = $this->invokeConformanceCell('failedClientCell', [
            [
                'outcome' => 'exception',
                'exception' => [
                    'status' => 422,
                    'body' => [
                        'update_status' => 'failed',
                        'failure_message' => 'PHP update failure cell',
                    ],
                ],
            ],
            ['type' => 'fail_update'],
        ]);
        $missingHandlerFailure = $this->invokeConformanceCell('failedClientCell', [
            [
                'outcome' => 'exception',
                'exception' => [
                    'status' => 422,
                    'body' => [
                        'update_status' => 'failed',
                        'failure_message' => 'PHP update failure cell',
                    ],
                ],
            ],
            ['type' => 'complete_update'],
        ]);

        $this->assertSame('pass', $passing['status']);
        $this->assertTrue($passing['evidence']['checks']['client_failed_exception']);
        $this->assertTrue($passing['evidence']['checks']['handler_failed']);
        $this->assertSame('fail', $missingHandlerFailure['status']);
        $this->assertFalse($missingHandlerFailure['evidence']['checks']['handler_failed']);
    }

    public function testPayloadCellRequiresHandlerAndClientPayloadRoundTrip(): void
    {
        $payload = [
            'string' => 'hello',
            'number' => 42,
            'nested' => [
                'bool' => true,
                'list' => [1, 2, 3],
            ],
        ];
        $passing = $this->invokeConformanceCell('payloadCell', [
            [
                'type' => 'complete_update',
                'decoded_result' => [
                    'received' => $payload,
                ],
            ],
            [
                'outcome' => 'response',
                'response' => [
                    'decoded_result' => [
                        'received' => $payload,
                    ],
                ],
            ],
            $payload,
        ]);
        $clientMismatch = $this->invokeConformanceCell('payloadCell', [
            [
                'type' => 'complete_update',
                'decoded_result' => [
                    'received' => $payload,
                ],
            ],
            [
                'outcome' => 'response',
                'response' => [
                    'decoded_result' => [
                        'received' => ['string' => 'different'],
                    ],
                ],
            ],
            $payload,
        ]);

        $this->assertSame('pass', $passing['status']);
        $this->assertTrue($passing['evidence']['checks']['handler_received_expected_payload']);
        $this->assertTrue($passing['evidence']['checks']['client_received_expected_payload']);
        $this->assertSame('fail', $clientMismatch['status']);
        $this->assertFalse($clientMismatch['evidence']['checks']['client_received_expected_payload']);
    }

    /**
     * @param list<mixed> $arguments
     * @return array<string, mixed>
     */
    private function invokeConformanceCell(string $method, array $arguments): array
    {
        $command = $this->app->make(V2WorkflowUpdatesConformanceCommand::class);
        $reflection = new ReflectionClass($command);
        $cell = $reflection->getMethod($method)->invokeArgs($command, $arguments);

        $this->assertIsArray($cell);

        return $cell;
    }
}
