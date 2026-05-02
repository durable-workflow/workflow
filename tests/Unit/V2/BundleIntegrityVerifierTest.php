<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Tests\TestCase;
use Workflow\V2\Support\BundleIntegrityVerifier;
use Workflow\V2\Support\HistoryExport;

final class BundleIntegrityVerifierTest extends TestCase
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
