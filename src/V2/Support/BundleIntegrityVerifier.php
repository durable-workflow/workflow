<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonException;
use LogicException;
use Workflow\Serializers\Avro;

/**
 * Offline integrity verifier for HistoryExport bundles.
 *
 * Re-derives the canonical checksum from the bundle body (excluding the
 * recorded `integrity` block) and compares it against the recorded
 * checksum and, when configured, the HMAC signature. Also surfaces
 * structural problems an offline operator or CI runner can act on without
 * needing the workflow runtime: schema/version drift, missing required
 * sections, payload-manifest entries pointing at unavailable bytes, Avro
 * frames whose writer-schema fingerprint disagrees with the embedded
 * schema, and history events that drift from their own command rows.
 *
 * Verification has no side effects and never touches the database; it is
 * the contract surface the offline replay CLI and CI gates depend on.
 *
 * @api Stable v2 contract surface for offline replay verification.
 */
final class BundleIntegrityVerifier
{
    public const REPORT_SCHEMA = 'durable-workflow.v2.history-bundle-verification';

    public const REPORT_SCHEMA_VERSION = 1;

    public const STATUS_OK = 'ok';

    public const STATUS_WARNING = 'warning';

    public const STATUS_FAILED = 'failed';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_ERROR = 'error';

    /**
     * Verify a decoded history-export bundle.
     *
     * @param array<string, mixed> $bundle
     * @param string|null $signingKey HMAC key used to verify the signature; when null falls back to config().
     *
     * @return array{
     *     schema: string,
     *     schema_version: int,
     *     status: string,
     *     bundle: array{
     *         schema: ?string,
     *         schema_version: ?int,
     *         exported_at: ?string,
     *         dedupe_key: ?string,
     *         workflow_run_id: ?string,
     *         workflow_instance_id: ?string,
     *         history_event_count: int,
     *     },
     *     integrity: array{
     *         present: bool,
     *         canonicalization: ?string,
     *         checksum_algorithm: ?string,
     *         expected_checksum: ?string,
     *         recomputed_checksum: ?string,
     *         checksum_matches: ?bool,
     *         signature_algorithm: ?string,
     *         signature_present: bool,
     *         signature_verified: ?bool,
     *         key_id: ?string,
     *     },
     *     summary: array{
     *         errors: int,
     *         warnings: int,
     *         info: int,
     *         findings_by_rule: array<string, int>
     *     },
     *     findings: list<array{
     *         rule: string,
     *         severity: string,
     *         message: string,
     *         path: ?string,
     *         context: array<string, mixed>
     *     }>
     * }
     */
    public static function verify(array $bundle, ?string $signingKey = null): array
    {
        $findings = [];
        $rawIntegrity = $bundle['integrity'] ?? null;
        $unsignedBundle = $bundle;
        unset($unsignedBundle['integrity']);

        self::checkSchema($bundle, $findings);
        self::checkRequiredSections($bundle, $findings);
        self::checkRunReferences($bundle, $findings);
        self::checkPayloadManifest($bundle, $findings);
        self::checkHistoryEvents($bundle, $findings);
        self::checkCommandHistoryConsistency($bundle, $findings);
        self::checkRedaction($bundle, $findings);

        $integrityReport = self::checkIntegrity($rawIntegrity, $unsignedBundle, $signingKey, $findings);

        $bundleSummary = [
            'schema' => self::stringValue($bundle['schema'] ?? null),
            'schema_version' => self::intValue($bundle['schema_version'] ?? null),
            'exported_at' => self::stringValue($bundle['exported_at'] ?? null),
            'dedupe_key' => self::stringValue($bundle['dedupe_key'] ?? null),
            'workflow_run_id' => self::stringValue(($bundle['workflow']['run_id'] ?? null)),
            'workflow_instance_id' => self::stringValue(($bundle['workflow']['instance_id'] ?? null)),
            'history_event_count' => self::eventCount($bundle),
        ];

        $status = self::aggregateStatus($findings);
        $summary = self::summarizeFindings($findings);

        return [
            'schema' => self::REPORT_SCHEMA,
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'status' => $status,
            'bundle' => $bundleSummary,
            'integrity' => $integrityReport,
            'summary' => $summary,
            'findings' => $findings,
        ];
    }

    /**
     * Decode and verify a bundle from raw JSON. Surface decode failures as
     * a single `bundle_unparseable` error so callers can still emit a
     * machine-readable report instead of crashing.
     *
     * @return array<string, mixed>
     */
    public static function verifyJson(string $json, ?string $signingKey = null): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return self::unparseableReport($exception->getMessage());
        }

        if (! is_array($decoded)) {
            return self::unparseableReport('Bundle JSON must decode to an object.');
        }

        return self::verify($decoded, $signingKey);
    }

    /**
     * @param list<array{rule: string, severity: string, message: string, path: ?string, context: array<string, mixed>}> $findings
     */
    private static function checkSchema(array $bundle, array &$findings): void
    {
        $schema = self::stringValue($bundle['schema'] ?? null);
        $version = self::intValue($bundle['schema_version'] ?? null);

        if ($schema === null) {
            self::addFinding($findings, 'bundle.schema_missing', self::SEVERITY_ERROR, 'Bundle is missing the schema field.', 'schema');
        } elseif ($schema !== HistoryExport::SCHEMA) {
            self::addFinding(
                $findings,
                'bundle.schema_unexpected',
                self::SEVERITY_ERROR,
                sprintf('Bundle schema [%s] does not match expected [%s].', $schema, HistoryExport::SCHEMA),
                'schema',
                ['expected' => HistoryExport::SCHEMA, 'actual' => $schema],
            );
        }

        if ($version === null) {
            self::addFinding($findings, 'bundle.schema_version_missing', self::SEVERITY_ERROR, 'Bundle is missing schema_version.', 'schema_version');
        } elseif ($version !== HistoryExport::SCHEMA_VERSION) {
            self::addFinding(
                $findings,
                'bundle.schema_version_unsupported',
                self::SEVERITY_ERROR,
                sprintf(
                    'Bundle schema_version %d is not supported by this verifier (expected %d).',
                    $version,
                    HistoryExport::SCHEMA_VERSION,
                ),
                'schema_version',
                ['expected' => HistoryExport::SCHEMA_VERSION, 'actual' => $version],
            );
        }

        if (self::stringValue($bundle['exported_at'] ?? null) === null) {
            self::addFinding(
                $findings,
                'bundle.exported_at_missing',
                self::SEVERITY_WARNING,
                'Bundle is missing exported_at; replay reports cannot be ordered without it.',
                'exported_at',
            );
        }
    }

    /**
     * @param list<array{rule: string, severity: string, message: string, path: ?string, context: array<string, mixed>}> $findings
     */
    private static function checkRequiredSections(array $bundle, array &$findings): void
    {
        $required = [
            'workflow' => 'object',
            'payloads' => 'object',
            'history_events' => 'list',
            'commands' => 'list',
            'signals' => 'list',
            'updates' => 'list',
            'tasks' => 'list',
            'activities' => 'list',
            'timers' => 'list',
            'failures' => 'list',
            'links' => 'object',
            'redaction' => 'object',
            'codec_schemas' => 'object',
            'payload_manifest' => 'object',
        ];

        foreach ($required as $key => $shape) {
            if (! array_key_exists($key, $bundle)) {
                self::addFinding(
                    $findings,
                    'bundle.section_missing',
                    self::SEVERITY_ERROR,
                    sprintf('Bundle is missing required section [%s].', $key),
                    $key,
                );

                continue;
            }

            if ($shape === 'object' && ! is_array($bundle[$key])) {
                self::addFinding(
                    $findings,
                    'bundle.section_invalid',
                    self::SEVERITY_ERROR,
                    sprintf('Bundle section [%s] must be an object.', $key),
                    $key,
                );
            }

            if ($shape === 'list' && ! is_array($bundle[$key])) {
                self::addFinding(
                    $findings,
                    'bundle.section_invalid',
                    self::SEVERITY_ERROR,
                    sprintf('Bundle section [%s] must be a list.', $key),
                    $key,
                );
            }
        }
    }

    /**
     * @param list<array{rule: string, severity: string, message: string, path: ?string, context: array<string, mixed>}> $findings
     */
    private static function checkRunReferences(array $bundle, array &$findings): void
    {
        $workflow = is_array($bundle['workflow'] ?? null) ? $bundle['workflow'] : [];

        $runId = self::stringValue($workflow['run_id'] ?? null);
        $instanceId = self::stringValue($workflow['instance_id'] ?? null);

        if ($runId === null) {
            self::addFinding(
                $findings,
                'workflow.run_id_missing',
                self::SEVERITY_ERROR,
                'Bundle workflow.run_id is required.',
                'workflow.run_id',
            );
        }

        if ($instanceId === null) {
            self::addFinding(
                $findings,
                'workflow.instance_id_missing',
                self::SEVERITY_ERROR,
                'Bundle workflow.instance_id is required.',
                'workflow.instance_id',
            );
        }

        if (self::stringValue($workflow['workflow_type'] ?? null) === null) {
            self::addFinding(
                $findings,
                'workflow.workflow_type_missing',
                self::SEVERITY_ERROR,
                'Bundle workflow.workflow_type is required.',
                'workflow.workflow_type',
            );
        }

        $lastSequence = self::intValue($workflow['last_history_sequence'] ?? null);
        $eventCount = self::eventCount($bundle);

        if ($lastSequence !== null && $eventCount > 0 && $lastSequence < $eventCount) {
            self::addFinding(
                $findings,
                'workflow.last_history_sequence_stale',
                self::SEVERITY_WARNING,
                sprintf(
                    'Workflow last_history_sequence (%d) is below the recorded event count (%d); the bundle may be truncated.',
                    $lastSequence,
                    $eventCount,
                ),
                'workflow.last_history_sequence',
                ['last_history_sequence' => $lastSequence, 'history_event_count' => $eventCount],
            );
        }
    }

    /**
     * @param list<array{rule: string, severity: string, message: string, path: ?string, context: array<string, mixed>}> $findings
     */
    private static function checkPayloadManifest(array $bundle, array &$findings): void
    {
        $manifest = is_array($bundle['payload_manifest'] ?? null) ? $bundle['payload_manifest'] : null;

        if ($manifest === null) {
            return;
        }

        $entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $path = self::stringValue($entry['path'] ?? null) ?? "payload_manifest.entries[{$index}]";
            $codec = self::stringValue($entry['codec'] ?? null);
            $available = (bool) ($entry['available'] ?? false);
            $redacted = (bool) ($entry['redacted'] ?? false);
            $diagnostic = self::stringValue($entry['diagnostic'] ?? null);
            $writerSchema = self::stringValue($entry['writer_schema'] ?? null);
            $writerSchemaFingerprint = self::stringValue($entry['writer_schema_fingerprint'] ?? null);

            if ($codec === null) {
                self::addFinding(
                    $findings,
                    'payload_manifest.codec_missing',
                    self::SEVERITY_ERROR,
                    sprintf('Payload manifest entry [%s] is missing codec.', $path),
                    $path,
                );
            }

            if (! $redacted && $available && $diagnostic === 'payload_missing') {
                self::addFinding(
                    $findings,
                    'payload_manifest.payload_missing',
                    self::SEVERITY_ERROR,
                    sprintf('Manifest reports payload [%s] available but bundle has no payload bytes.', $path),
                    $path,
                );
            }

            if ($codec === 'avro' && $available && ! $redacted) {
                $framing = self::stringValue($entry['avro_framing'] ?? null);

                if ($framing === null) {
                    self::addFinding(
                        $findings,
                        'payload_manifest.avro_framing_missing',
                        self::SEVERITY_WARNING,
                        sprintf('Avro payload [%s] is missing framing metadata; offline decoders cannot pick a wrapper schema.', $path),
                        $path,
                    );
                }

                if ($writerSchema !== null && $writerSchemaFingerprint !== null) {
                    $expected = 'sha256:' . hash('sha256', $writerSchema);

                    if ($expected !== $writerSchemaFingerprint) {
                        self::addFinding(
                            $findings,
                            'payload_manifest.writer_schema_fingerprint_mismatch',
                            self::SEVERITY_ERROR,
                            sprintf(
                                'Avro payload [%s] writer_schema_fingerprint disagrees with the embedded schema; offline decoders will reject this entry.',
                                $path,
                            ),
                            $path,
                            ['expected' => $expected, 'actual' => $writerSchemaFingerprint],
                        );
                    }
                }
            }
        }

        if (! is_array($bundle['codec_schemas'] ?? null)) {
            return;
        }

        $codecSchemas = $bundle['codec_schemas'];

        if (isset($codecSchemas['avro']) && is_array($codecSchemas['avro'])) {
            $avro = $codecSchemas['avro'];
            $wrapperSchema = self::stringValue($avro['wrapper_schema'] ?? null);
            $expectedFingerprint = self::stringValue(
                ($avro['generic_wrapper']['writer_schema_fingerprint'] ?? null),
            );

            if ($wrapperSchema !== null && $expectedFingerprint !== null) {
                $derived = 'sha256:' . hash('sha256', $wrapperSchema);

                if ($derived !== $expectedFingerprint) {
                    self::addFinding(
                        $findings,
                        'codec_schemas.wrapper_fingerprint_mismatch',
                        self::SEVERITY_ERROR,
                        'Avro generic_wrapper.writer_schema_fingerprint disagrees with the embedded wrapper_schema.',
                        'codec_schemas.avro.generic_wrapper.writer_schema_fingerprint',
                        ['expected' => $derived, 'actual' => $expectedFingerprint],
                    );
                }
            }

            if ($wrapperSchema !== null) {
                $runtimeWrapper = Avro::wrapperSchemaJson();

                if ($wrapperSchema !== $runtimeWrapper) {
                    self::addFinding(
                        $findings,
                        'codec_schemas.wrapper_schema_drift',
                        self::SEVERITY_WARNING,
                        'Embedded Avro wrapper_schema differs from this runtime; offline decoders should prefer the embedded schema.',
                        'codec_schemas.avro.wrapper_schema',
                    );
                }
            }
        }
    }

    /**
     * @param list<array{rule: string, severity: string, message: string, path: ?string, context: array<string, mixed>}> $findings
     */
    private static function checkHistoryEvents(array $bundle, array &$findings): void
    {
        $events = is_array($bundle['history_events'] ?? null) ? $bundle['history_events'] : [];

        if ($events === []) {
            return;
        }

        $previousSequence = null;
        $seenIds = [];

        foreach ($events as $index => $event) {
            $path = "history_events[{$index}]";

            if (! is_array($event)) {
                self::addFinding(
                    $findings,
                    'history_events.entry_invalid',
                    self::SEVERITY_ERROR,
                    'History event entry must be an object.',
                    $path,
                );

                continue;
            }

            $sequence = self::intValue($event['sequence'] ?? null);
            $type = self::stringValue($event['type'] ?? null);
            $id = self::stringValue($event['id'] ?? null);

            if ($sequence === null) {
                self::addFinding(
                    $findings,
                    'history_events.sequence_missing',
                    self::SEVERITY_ERROR,
                    'History event is missing sequence.',
                    $path,
                );
            } elseif ($previousSequence !== null && $sequence <= $previousSequence) {
                self::addFinding(
                    $findings,
                    'history_events.sequence_not_monotonic',
                    self::SEVERITY_ERROR,
                    sprintf(
                        'History event sequence %d does not advance past previous sequence %d.',
                        $sequence,
                        $previousSequence,
                    ),
                    $path,
                    ['sequence' => $sequence, 'previous_sequence' => $previousSequence],
                );
            }

            if ($sequence !== null) {
                $previousSequence = $sequence;
            }

            if ($type === null) {
                self::addFinding(
                    $findings,
                    'history_events.type_missing',
                    self::SEVERITY_ERROR,
                    'History event is missing type.',
                    $path,
                );
            }

            if ($id !== null) {
                if (isset($seenIds[$id])) {
                    self::addFinding(
                        $findings,
                        'history_events.id_duplicate',
                        self::SEVERITY_ERROR,
                        sprintf('History event id [%s] appears more than once.', $id),
                        $path,
                        ['id' => $id, 'first_seen_at' => $seenIds[$id]],
                    );
                } else {
                    $seenIds[$id] = $path;
                }
            }
        }
    }

    /**
     * @param list<array{rule: string, severity: string, message: string, path: ?string, context: array<string, mixed>}> $findings
     */
    private static function checkCommandHistoryConsistency(array $bundle, array &$findings): void
    {
        $commands = is_array($bundle['commands'] ?? null) ? $bundle['commands'] : [];
        $events = is_array($bundle['history_events'] ?? null) ? $bundle['history_events'] : [];

        $eventsByCommand = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $commandId = self::stringValue($event['workflow_command_id'] ?? null);

            if ($commandId === null) {
                continue;
            }

            $eventsByCommand[$commandId] = ($eventsByCommand[$commandId] ?? 0) + 1;
        }

        foreach ($commands as $index => $command) {
            if (! is_array($command)) {
                continue;
            }

            $id = self::stringValue($command['id'] ?? null);
            $status = self::stringValue($command['status'] ?? null);
            $outcome = self::stringValue($command['outcome'] ?? null);
            $appliedAt = self::stringValue($command['applied_at'] ?? null);
            $rejectedAt = self::stringValue($command['rejected_at'] ?? null);

            if ($id === null) {
                self::addFinding(
                    $findings,
                    'commands.id_missing',
                    self::SEVERITY_ERROR,
                    'Command row is missing id.',
                    "commands[{$index}]",
                );

                continue;
            }

            $hasEvent = isset($eventsByCommand[$id]);
            $isSettled = $status === 'applied' || $status === 'rejected'
                || $outcome === 'applied' || $outcome === 'rejected'
                || $appliedAt !== null || $rejectedAt !== null;

            if ($isSettled && ! $hasEvent) {
                self::addFinding(
                    $findings,
                    'commands.history_event_missing',
                    self::SEVERITY_WARNING,
                    sprintf(
                        'Command [%s] is settled (status=%s, outcome=%s) but no history event references it.',
                        $id,
                        $status ?? 'null',
                        $outcome ?? 'null',
                    ),
                    "commands[{$index}]",
                    ['command_id' => $id, 'status' => $status, 'outcome' => $outcome],
                );
            }
        }
    }

    /**
     * @param list<array{rule: string, severity: string, message: string, path: ?string, context: array<string, mixed>}> $findings
     */
    private static function checkRedaction(array $bundle, array &$findings): void
    {
        $redaction = is_array($bundle['redaction'] ?? null) ? $bundle['redaction'] : null;

        if ($redaction === null) {
            return;
        }

        $applied = (bool) ($redaction['applied'] ?? false);
        $paths = is_array($redaction['paths'] ?? null) ? $redaction['paths'] : [];

        if ($applied && $paths === []) {
            self::addFinding(
                $findings,
                'redaction.empty_paths',
                self::SEVERITY_INFO,
                'Redaction is marked applied but no paths were redacted; replay-diff diagnostics will treat all payloads as in-scope.',
                'redaction.paths',
            );
        }
    }

    /**
     * @param list<array{rule: string, severity: string, message: string, path: ?string, context: array<string, mixed>}> $findings
     *
     * @return array{
     *     present: bool,
     *     canonicalization: ?string,
     *     checksum_algorithm: ?string,
     *     expected_checksum: ?string,
     *     recomputed_checksum: ?string,
     *     checksum_matches: ?bool,
     *     signature_algorithm: ?string,
     *     signature_present: bool,
     *     signature_verified: ?bool,
     *     key_id: ?string
     * }
     */
    private static function checkIntegrity(
        mixed $rawIntegrity,
        array $unsignedBundle,
        ?string $signingKey,
        array &$findings,
    ): array {
        $report = [
            'present' => false,
            'canonicalization' => null,
            'checksum_algorithm' => null,
            'expected_checksum' => null,
            'recomputed_checksum' => null,
            'checksum_matches' => null,
            'signature_algorithm' => null,
            'signature_present' => false,
            'signature_verified' => null,
            'key_id' => null,
        ];

        if (! is_array($rawIntegrity)) {
            self::addFinding(
                $findings,
                'integrity.missing',
                self::SEVERITY_ERROR,
                'Bundle has no integrity block; offline replay verification cannot vouch for its contents.',
                'integrity',
            );

            return $report;
        }

        $report['present'] = true;
        $report['canonicalization'] = self::stringValue($rawIntegrity['canonicalization'] ?? null);
        $report['checksum_algorithm'] = self::stringValue($rawIntegrity['checksum_algorithm'] ?? null);
        $report['expected_checksum'] = self::stringValue($rawIntegrity['checksum'] ?? null);
        $report['signature_algorithm'] = self::stringValue($rawIntegrity['signature_algorithm'] ?? null);
        $report['key_id'] = self::stringValue($rawIntegrity['key_id'] ?? null);
        $signature = self::stringValue($rawIntegrity['signature'] ?? null);
        $report['signature_present'] = $signature !== null;

        if ($report['canonicalization'] !== 'json-recursive-ksort-v1') {
            self::addFinding(
                $findings,
                'integrity.canonicalization_unsupported',
                self::SEVERITY_ERROR,
                sprintf('Unsupported canonicalization [%s]; only json-recursive-ksort-v1 is recognized offline.', $report['canonicalization'] ?? 'null'),
                'integrity.canonicalization',
            );

            return $report;
        }

        if ($report['checksum_algorithm'] !== 'sha256') {
            self::addFinding(
                $findings,
                'integrity.checksum_algorithm_unsupported',
                self::SEVERITY_ERROR,
                sprintf('Unsupported checksum algorithm [%s]; only sha256 is recognized.', $report['checksum_algorithm'] ?? 'null'),
                'integrity.checksum_algorithm',
            );

            return $report;
        }

        try {
            $canonical = self::canonicalJson($unsignedBundle);
        } catch (LogicException $exception) {
            self::addFinding(
                $findings,
                'integrity.canonicalization_failed',
                self::SEVERITY_ERROR,
                'Could not canonicalize bundle for integrity verification: ' . $exception->getMessage(),
                'integrity',
            );

            return $report;
        }

        $report['recomputed_checksum'] = hash('sha256', $canonical);

        if ($report['expected_checksum'] === null) {
            self::addFinding(
                $findings,
                'integrity.checksum_missing',
                self::SEVERITY_ERROR,
                'Integrity block is missing checksum.',
                'integrity.checksum',
            );

            $report['checksum_matches'] = false;
        } else {
            $report['checksum_matches'] = hash_equals($report['expected_checksum'], $report['recomputed_checksum']);

            if (! $report['checksum_matches']) {
                self::addFinding(
                    $findings,
                    'integrity.checksum_mismatch',
                    self::SEVERITY_ERROR,
                    'Recomputed bundle checksum does not match the recorded checksum; the bundle has been altered after export.',
                    'integrity.checksum',
                    ['expected' => $report['expected_checksum'], 'actual' => $report['recomputed_checksum']],
                );
            }
        }

        if ($report['signature_algorithm'] === null && ! $report['signature_present']) {
            return $report;
        }

        if ($report['signature_algorithm'] !== 'hmac-sha256') {
            self::addFinding(
                $findings,
                'integrity.signature_algorithm_unsupported',
                self::SEVERITY_ERROR,
                sprintf(
                    'Unsupported signature algorithm [%s]; only hmac-sha256 is recognized.',
                    $report['signature_algorithm'] ?? 'null',
                ),
                'integrity.signature_algorithm',
            );

            return $report;
        }

        if (! $report['signature_present']) {
            self::addFinding(
                $findings,
                'integrity.signature_missing',
                self::SEVERITY_ERROR,
                'Integrity block declares a signature_algorithm but carries no signature.',
                'integrity.signature',
            );

            return $report;
        }

        $resolvedKey = $signingKey ?? self::configuredSigningKey();

        if ($resolvedKey === null) {
            self::addFinding(
                $findings,
                'integrity.signature_key_unavailable',
                self::SEVERITY_WARNING,
                'Bundle is signed but no verification key is configured; signature was not verified.',
                'integrity.signature',
            );

            return $report;
        }

        $expected = hash_hmac('sha256', $canonical, $resolvedKey);
        $report['signature_verified'] = hash_equals($expected, $signature ?? '');

        if (! $report['signature_verified']) {
            self::addFinding(
                $findings,
                'integrity.signature_mismatch',
                self::SEVERITY_ERROR,
                'Bundle signature does not match the configured key; treat the bundle as untrusted.',
                'integrity.signature',
                ['key_id' => $report['key_id']],
            );
        }

        return $report;
    }

    /**
     * @param list<array{rule: string, severity: string, message: string, path: ?string, context: array<string, mixed>}> $findings
     */
    private static function aggregateStatus(array $findings): string
    {
        $status = self::STATUS_OK;

        foreach ($findings as $finding) {
            if ($finding['severity'] === self::SEVERITY_ERROR) {
                return self::STATUS_FAILED;
            }

            if ($finding['severity'] === self::SEVERITY_WARNING) {
                $status = self::STATUS_WARNING;
            }
        }

        return $status;
    }

    /**
     * @param list<array{rule: string, severity: string, message: string, path: ?string, context: array<string, mixed>}> $findings
     *
     * @return array{errors: int, warnings: int, info: int, findings_by_rule: array<string, int>}
     */
    private static function summarizeFindings(array $findings): array
    {
        $errors = 0;
        $warnings = 0;
        $info = 0;
        $byRule = [];

        foreach ($findings as $finding) {
            $rule = $finding['rule'];
            $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;

            match ($finding['severity']) {
                self::SEVERITY_ERROR => $errors++,
                self::SEVERITY_WARNING => $warnings++,
                self::SEVERITY_INFO => $info++,
                default => null,
            };
        }

        ksort($byRule);

        return [
            'errors' => $errors,
            'warnings' => $warnings,
            'info' => $info,
            'findings_by_rule' => $byRule,
        ];
    }

    /**
     * @param list<array{rule: string, severity: string, message: string, path: ?string, context: array<string, mixed>}> $findings
     * @param array<string, mixed> $context
     */
    private static function addFinding(
        array &$findings,
        string $rule,
        string $severity,
        string $message,
        ?string $path = null,
        array $context = [],
    ): void {
        $findings[] = [
            'rule' => $rule,
            'severity' => $severity,
            'message' => $message,
            'path' => $path,
            'context' => $context,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function unparseableReport(string $message): array
    {
        return [
            'schema' => self::REPORT_SCHEMA,
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'status' => self::STATUS_FAILED,
            'bundle' => [
                'schema' => null,
                'schema_version' => null,
                'exported_at' => null,
                'dedupe_key' => null,
                'workflow_run_id' => null,
                'workflow_instance_id' => null,
                'history_event_count' => 0,
            ],
            'integrity' => [
                'present' => false,
                'canonicalization' => null,
                'checksum_algorithm' => null,
                'expected_checksum' => null,
                'recomputed_checksum' => null,
                'checksum_matches' => null,
                'signature_algorithm' => null,
                'signature_present' => false,
                'signature_verified' => null,
                'key_id' => null,
            ],
            'summary' => [
                'errors' => 1,
                'warnings' => 0,
                'info' => 0,
                'findings_by_rule' => ['bundle.unparseable' => 1],
            ],
            'findings' => [
                [
                    'rule' => 'bundle.unparseable',
                    'severity' => self::SEVERITY_ERROR,
                    'message' => 'Bundle JSON could not be decoded: ' . $message,
                    'path' => null,
                    'context' => [],
                ],
            ],
        ];
    }

    private static function eventCount(array $bundle): int
    {
        $events = $bundle['history_events'] ?? null;

        return is_array($events) ? count($events) : 0;
    }

    private static function canonicalJson(mixed $value): string
    {
        $json = json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        if (! is_string($json)) {
            throw new LogicException('Failed to canonicalize history bundle.');
        }

        return $json;
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

    private static function configuredSigningKey(): ?string
    {
        if (! function_exists('config')) {
            return null;
        }

        $key = config('workflows.v2.history_export.signing_key');

        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return $key === '' ? null : $key;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }

    private static function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value) && (string) (int) $value === $value) {
            return (int) $value;
        }

        return null;
    }
}
