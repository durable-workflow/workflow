<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\NonDatabaseTestCase;
use Workflow\Serializers\Avro;
use Workflow\V2\Support\BundleIntegrityVerifier;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\MemoPayload;

final class BundleIntegrityVerifierTest extends NonDatabaseTestCase
{
    public function testValidBundlePassesAllChecks(): void
    {
        $bundle = self::wellFormedBundle();

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::REPORT_SCHEMA, $report['schema']);
        $this->assertSame(BundleIntegrityVerifier::STATUS_OK, $report['status']);
        $this->assertSame(0, $report['summary']['errors']);
        $this->assertSame(0, $report['summary']['warnings']);
        $this->assertTrue($report['integrity']['present']);
        $this->assertTrue($report['integrity']['checksum_matches']);
        $this->assertNull($report['integrity']['signature_verified']);
        $this->assertSame([], $report['findings']);
        $this->assertSame('run-1', $report['bundle']['workflow_run_id']);
        $this->assertSame(2, $report['bundle']['history_event_count']);
    }

    public function testChecksumMismatchProducesFailure(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['integrity']['checksum'] = str_repeat('0', 64);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $this->assertFalse($report['integrity']['checksum_matches']);

        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('integrity.checksum_mismatch', $rules);
    }

    public function testMissingIntegrityBlockFailsLoudly(): void
    {
        $bundle = self::wellFormedBundle();
        unset($bundle['integrity']);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $this->assertFalse($report['integrity']['present']);

        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('integrity.missing', $rules);
    }

    public function testSchemaDriftIsReported(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['schema'] = 'durable-workflow.v2.history-export-other';
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('bundle.schema_unexpected', $rules);
    }

    public function testMissingSchemaMetadataIsReported(): void
    {
        $bundle = self::wellFormedBundle();
        unset($bundle['schema'], $bundle['schema_version'], $bundle['exported_at']);
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('bundle.schema_missing', $rules);
        $this->assertContains('bundle.schema_version_missing', $rules);
        $this->assertContains('bundle.exported_at_missing', $rules);
    }

    public function testUnsupportedSchemaVersionIsReported(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['schema_version'] = HistoryExport::SCHEMA_VERSION + 1;
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $finding = $this->finding($report, 'bundle.schema_version_unsupported');
        $this->assertSame(HistoryExport::SCHEMA_VERSION, $finding['context']['expected']);
        $this->assertSame(HistoryExport::SCHEMA_VERSION + 1, $finding['context']['actual']);
    }

    public function testRequiredSectionsMustExist(): void
    {
        $requiredSections = [
            'workflow',
            'payloads',
            'history_events',
            'commands',
            'signals',
            'updates',
            'tasks',
            'activities',
            'timers',
            'failures',
            'links',
            'redaction',
            'codec_schemas',
            'payload_manifest',
        ];

        foreach ($requiredSections as $section) {
            $bundle = self::wellFormedBundle();
            unset($bundle[$section]);
            $bundle['integrity'] = self::buildIntegrity($bundle);

            $finding = $this->finding(
                BundleIntegrityVerifier::verify($bundle),
                'bundle.section_missing',
                $section,
            );

            $this->assertSame($section, $finding['path']);
        }
    }

    public function testRequiredSectionsRejectInvalidShapes(): void
    {
        foreach ([
            'payloads' => 'an object',
            'history_events' => 'a list',
        ] as $section => $shape) {
            $bundle = self::wellFormedBundle();
            $bundle[$section] = 'invalid';
            $bundle['integrity'] = self::buildIntegrity($bundle);

            $finding = $this->finding(
                BundleIntegrityVerifier::verify($bundle),
                'bundle.section_invalid',
                $section,
            );

            $this->assertSame("Bundle section [{$section}] must be {$shape}.", $finding['message']);
        }
    }

    public function testNonMonotonicHistorySequenceIsReported(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['history_events'][1]['sequence'] = $bundle['history_events'][0]['sequence'];
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('history_events.sequence_not_monotonic', $rules);
        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
    }

    public function testDuplicateHistoryEventIdsAreReported(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['history_events'][1]['id'] = $bundle['history_events'][0]['id'];
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('history_events.id_duplicate', $rules);
    }

    public function testLastHistorySequenceBelowHighestEventSequenceIsWarned(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['history_events'][1]['sequence'] = 3;
        $bundle['workflow']['last_history_sequence'] = 2;
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('workflow.last_history_sequence_stale', $rules);
        $this->assertSame(BundleIntegrityVerifier::STATUS_WARNING, $report['status']);
    }

    public function testMissingPortableMemoAuthorityIsRejected(): void
    {
        $bundle = self::wellFormedBundle();
        unset($bundle['workflow']['memo_payload']);
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $this->assertContains('workflow.memo_payload_missing', array_column($report['findings'], 'rule'));
    }

    public function testWorkflowIdentityFieldsAreRequired(): void
    {
        $bundle = self::wellFormedBundle();
        unset(
            $bundle['workflow']['run_id'],
            $bundle['workflow']['instance_id'],
            $bundle['workflow']['workflow_type'],
        );
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('workflow.run_id_missing', $rules);
        $this->assertContains('workflow.instance_id_missing', $rules);
        $this->assertContains('workflow.workflow_type_missing', $rules);
    }

    public function testInvalidPortableMemoAuthorityIsRejected(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['workflow']['memo_payload'] = [
            'codec' => 'avro',
        ];
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $finding = $this->finding($report, 'workflow.memo_payload_invalid');
        $this->assertStringContainsString('expected exactly', $finding['message']);
    }

    public function testMemoProjectionMustMatchPortableAuthority(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['workflow']['memo'] = [
            'display' => 'drifted',
        ];
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $this->assertContains('workflow.memo_projection_mismatch', array_column($report['findings'], 'rule'));
    }

    public function testPayloadManifestMissingPayloadIsReported(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['payload_manifest']['entries'][] = [
            'path' => 'payloads.arguments.data',
            'codec' => 'workflow-serializer',
            'available' => true,
            'redacted' => false,
            'encoding' => 'opaque-string',
            'avro_framing' => null,
            'avro_prefix_hex' => null,
            'writer_schema' => null,
            'writer_schema_fingerprint' => null,
            'diagnostic' => 'payload_missing',
        ];
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('payload_manifest.payload_missing', $rules);
        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
    }

    public function testMalformedPayloadManifestEntriesAndMissingCodecMetadataAreReported(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['payload_manifest']['entries'] = [
            'not-an-object',
            [
                'available' => false,
            ],
            [
                'path' => 'payloads.arguments.data',
                'codec' => 'avro',
                'available' => true,
                'redacted' => false,
                'diagnostic' => null,
            ],
        ];
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $this->assertSame(
            'payload_manifest.entries[0]',
            $this->finding($report, 'payload_manifest.entry_invalid')['path'],
        );
        $this->assertSame(
            'payload_manifest.entries[1]',
            $this->finding($report, 'payload_manifest.codec_missing')['path'],
        );
        $this->assertSame(
            'payloads.arguments.data',
            $this->finding($report, 'payload_manifest.avro_framing_missing')['path'],
        );
    }

    public function testBundledAvroSchemaMustBePresentAndMatchTheRuntime(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['codec_schemas']['avro'] = [
            'current_fingerprint' => 'missing',
            'writer_schemas' => [],
        ];
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $missingReport = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $missingReport['status']);
        $this->finding($missingReport, 'codec_schemas.value_schema_missing');

        $fingerprint = Avro::valueSchemaFingerprint();
        $bundle['codec_schemas']['avro'] = [
            'current_fingerprint' => $fingerprint,
            'writer_schemas' => [
                $fingerprint => [
                    'schema' => '{}',
                    'fingerprint' => 'crc64-avro:incorrect',
                ],
            ],
        ];
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $driftReport = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $driftReport['status']);
        $this->assertStringEndsWith(
            $fingerprint,
            (string) $this->finding($driftReport, 'codec_schemas.value_schema_drift')['path'],
        );
    }

    public function testWriterSchemaFingerprintMismatchIsReported(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['payload_manifest']['entries'][] = [
            'path' => 'commands.0.payload',
            'codec' => 'avro',
            'available' => true,
            'redacted' => false,
            'encoding' => 'base64-avro-binary',
            'avro_framing' => 'typed',
            'avro_prefix_hex' => '01',
            'writer_schema' => '"int"',
            'writer_schema_fingerprint' => 'sha256:' . str_repeat('a', 64),
            'diagnostic' => null,
        ];
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('payload_manifest.writer_schema_fingerprint_mismatch', $rules);
    }

    public function testCommandWithoutMatchingHistoryEventIsWarned(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['commands'][] = [
            'id' => 'cmd-orphan',
            'sequence' => 9,
            'type' => 'workflow.start',
            'status' => 'applied',
            'outcome' => 'applied',
            'applied_at' => '2026-04-09T12:01:00.000000Z',
        ];
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('commands.history_event_missing', $rules);
        $this->assertSame(BundleIntegrityVerifier::STATUS_WARNING, $report['status']);
    }

    public function testMalformedHistoryAndCommandRowsAreReported(): void
    {
        $bundle = self::wellFormedBundle();
        unset($bundle['history_events'][0]['type']);
        $bundle['history_events'][] = 'not-an-object';
        $bundle['commands'][] = 'not-an-object';
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $this->assertSame('history_events[0]', $this->finding($report, 'history_events.type_missing')['path']);
        $this->assertSame('history_events[2]', $this->finding($report, 'history_events.entry_invalid')['path']);
        $this->assertSame('commands[1]', $this->finding($report, 'commands.entry_invalid')['path']);
    }

    public function testAppliedRedactionWithoutPathsIsSummarizedAsInformation(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['redaction']['applied'] = true;
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_OK, $report['status']);
        $this->assertSame(1, $report['summary']['info']);
        $this->assertSame(
            BundleIntegrityVerifier::SEVERITY_INFO,
            $this->finding($report, 'redaction.empty_paths')['severity'],
        );
    }

    public function testUnsupportedIntegrityMetadataAndMissingValuesAreReported(): void
    {
        config()->set('workflows.v2.history_export.signing_key', null);

        $cases = [
            'integrity.canonicalization_unsupported' => static function (array &$integrity): void {
                $integrity['canonicalization'] = 'unknown';
            },
            'integrity.checksum_algorithm_unsupported' => static function (array &$integrity): void {
                $integrity['checksum_algorithm'] = 'md5';
            },
            'integrity.checksum_missing' => static function (array &$integrity): void {
                unset($integrity['checksum']);
            },
            'integrity.signature_algorithm_unsupported' => static function (array &$integrity): void {
                $integrity['signature_algorithm'] = 'rsa-sha256';
                $integrity['signature'] = 'signature';
            },
            'integrity.signature_missing' => static function (array &$integrity): void {
                $integrity['signature_algorithm'] = 'hmac-sha256';
                $integrity['signature'] = null;
            },
            'integrity.signature_key_unavailable' => static function (array &$integrity): void {
                $integrity['signature_algorithm'] = 'hmac-sha256';
                $integrity['signature'] = 'signature';
            },
        ];

        foreach ($cases as $rule => $mutate) {
            $bundle = self::wellFormedBundle();
            $mutate($bundle['integrity']);

            $report = BundleIntegrityVerifier::verify($bundle);

            $this->finding($report, $rule);
            $this->assertSame(
                $rule === 'integrity.signature_key_unavailable'
                    ? BundleIntegrityVerifier::STATUS_WARNING
                    : BundleIntegrityVerifier::STATUS_FAILED,
                $report['status'],
                $rule,
            );
        }
    }

    public function testInvalidUtf8ProducesACanonicalizationFindingInsteadOfEscaping(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['workflow']['workflow_type'] = "\xB1\x31";

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $this->assertStringContainsString(
            'Malformed UTF-8',
            $this->finding($report, 'integrity.canonicalization_failed')['message'],
        );
        $this->assertNull($report['integrity']['recomputed_checksum']);
    }

    public function testConfiguredSigningKeyIsTrimmedAndUsedWhenNoExplicitKeyIsProvided(): void
    {
        $bundle = self::wellFormedBundle();
        unset($bundle['integrity']);
        $canonicalJson = json_encode(
            self::canonicalize($bundle),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
        $key = 'history-export-secret';
        $bundle['integrity'] = [
            'canonicalization' => 'json-recursive-ksort-v1',
            'checksum_algorithm' => 'sha256',
            'checksum' => hash('sha256', $canonicalJson),
            'signature_algorithm' => 'hmac-sha256',
            'signature' => hash_hmac('sha256', $canonicalJson, $key),
            'key_id' => 'configured-key',
        ];
        config()
            ->set('workflows.v2.history_export.signing_key', "  {$key}  ");

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_OK, $report['status']);
        $this->assertTrue($report['integrity']['signature_verified']);
    }

    public function testNumericStringSchemaVersionIsNormalizedInTheReport(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['schema_version'] = (string) HistoryExport::SCHEMA_VERSION;
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $report = BundleIntegrityVerifier::verify($bundle);

        $this->assertSame(BundleIntegrityVerifier::STATUS_OK, $report['status']);
        $this->assertSame(HistoryExport::SCHEMA_VERSION, $report['bundle']['schema_version']);
    }

    public function testSignatureVerifiesWhenKeyMatches(): void
    {
        $bundle = self::wellFormedBundle();
        unset($bundle['integrity']);
        $canonicalJson = json_encode(
            self::canonicalize($bundle),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        $key = 'history-export-secret';
        $bundle['integrity'] = [
            'canonicalization' => 'json-recursive-ksort-v1',
            'checksum_algorithm' => 'sha256',
            'checksum' => hash('sha256', $canonicalJson),
            'signature_algorithm' => 'hmac-sha256',
            'signature' => hash_hmac('sha256', $canonicalJson, $key),
            'key_id' => 'primary-2026',
        ];

        $report = BundleIntegrityVerifier::verify($bundle, $key);

        $this->assertSame(BundleIntegrityVerifier::STATUS_OK, $report['status']);
        $this->assertTrue($report['integrity']['signature_verified']);
        $this->assertSame('primary-2026', $report['integrity']['key_id']);
    }

    public function testSignatureMismatchIsReported(): void
    {
        $bundle = self::wellFormedBundle();
        unset($bundle['integrity']);
        $canonicalJson = json_encode(
            self::canonicalize($bundle),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        $bundle['integrity'] = [
            'canonicalization' => 'json-recursive-ksort-v1',
            'checksum_algorithm' => 'sha256',
            'checksum' => hash('sha256', $canonicalJson),
            'signature_algorithm' => 'hmac-sha256',
            'signature' => hash_hmac('sha256', $canonicalJson, 'wrong-key'),
            'key_id' => 'primary-2026',
        ];

        $report = BundleIntegrityVerifier::verify($bundle, 'history-export-secret');

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $this->assertFalse($report['integrity']['signature_verified']);

        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('integrity.signature_mismatch', $rules);
    }

    public function testJsonEntryPointHandlesDecodeErrors(): void
    {
        $report = BundleIntegrityVerifier::verifyJson('not json');

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $rules = array_column($report['findings'], 'rule');
        $this->assertContains('bundle.unparseable', $rules);
    }

    public function testJsonEntryPointRejectsScalarDocuments(): void
    {
        $report = BundleIntegrityVerifier::verifyJson('42');

        $this->assertSame(BundleIntegrityVerifier::STATUS_FAILED, $report['status']);
        $finding = $this->finding($report, 'bundle.unparseable');
        $this->assertSame(
            'Bundle JSON could not be decoded: Bundle JSON must decode to an object.',
            $finding['message'],
        );
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array{rule: string, severity: string, message: string, path: ?string, context: array<string, mixed>}
     */
    private function finding(array $report, string $rule, ?string $path = null): array
    {
        foreach ($report['findings'] as $finding) {
            if ($finding['rule'] === $rule && ($path === null || $finding['path'] === $path)) {
                return $finding;
            }
        }

        $this->fail(sprintf('Finding [%s] at path [%s] was not reported.', $rule, $path ?? '*'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function wellFormedBundle(): array
    {
        $bundle = [
            'schema' => HistoryExport::SCHEMA,
            'schema_version' => HistoryExport::SCHEMA_VERSION,
            'exported_at' => '2026-04-09T12:05:00.000000Z',
            'dedupe_key' => 'run-1:2:2026-04-09T12:00:00.000000Z',
            'history_complete' => true,
            'workflow' => [
                'instance_id' => 'inst-1',
                'run_id' => 'run-1',
                'run_number' => 1,
                'workflow_type' => 'verifier.test',
                'workflow_class' => 'Tests\\Fixtures\\Verifier',
                'memo' => [],
                'memo_payload' => MemoPayload::mapEnvelope([]),
                'status' => 'completed',
                'last_history_sequence' => 2,
            ],
            'payloads' => [
                'codec' => 'workflow-serializer',
                'arguments' => [
                    'available' => false,
                    'data' => null,
                ],
                'output' => [
                    'available' => false,
                    'data' => null,
                ],
            ],
            'history_events' => [
                [
                    'id' => 'evt-1',
                    'sequence' => 1,
                    'type' => 'WorkflowStarted',
                    'workflow_command_id' => 'cmd-start',
                    'workflow_task_id' => null,
                    'recorded_at' => '2026-04-09T12:00:00.000000Z',
                    'payload' => [],
                ],
                [
                    'id' => 'evt-2',
                    'sequence' => 2,
                    'type' => 'WorkflowCompleted',
                    'workflow_command_id' => null,
                    'workflow_task_id' => null,
                    'recorded_at' => '2026-04-09T12:01:00.000000Z',
                    'payload' => [],
                ],
            ],
            'waits' => [],
            'timeline' => [],
            'linked_intakes_scope' => 'selected_run',
            'linked_intakes' => [],
            'commands' => [
                [
                    'id' => 'cmd-start',
                    'sequence' => 1,
                    'type' => 'workflow.start',
                    'status' => 'applied',
                    'outcome' => 'applied',
                    'applied_at' => '2026-04-09T12:00:00.000000Z',
                ],
            ],
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
            'summary' => [
                'history_event_count' => 2,
            ],
            'selected_run' => [
                'waits_projection_source' => 'rebuilt',
                'timeline_projection_source' => 'rebuilt',
                'timers_projection_source' => 'rebuilt',
                'timers_projection_rebuild_reasons' => [],
                'lineage_projection_source' => 'rebuilt',
            ],
        ];

        $bundle['integrity'] = self::buildIntegrity($bundle);

        return $bundle;
    }

    /**
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    private static function buildIntegrity(array $bundle): array
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
}
