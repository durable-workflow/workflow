<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Support\BundleIntegrityVerifier;
use Workflow\V2\Support\HistoryExport;

final class V2ReplayVerifyCommandTest extends TestCase
{
    public function testCommandSucceedsForWellFormedBundleWithSkipReplay(): void
    {
        $bundle = self::wellFormedBundle();
        $bundlePath = $this->writeBundle($bundle);
        $reportPath = $this->ephemeralPath('replay-verify-out');

        $this->artisan('workflow:v2:replay-verify', [
            'bundle' => $bundlePath,
            '--skip-replay' => true,
            '--output' => $reportPath,
        ])->assertSuccessful();

        $report = $this->readJson($reportPath);

        $this->assertSame('ok', $report['verdict']);
        $this->assertSame(BundleIntegrityVerifier::STATUS_OK, $report['integrity']['status']);
        $this->assertNull($report['replay_diff']);
    }

    public function testCommandFailsWhenChecksumMismatched(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['integrity']['checksum'] = str_repeat('0', 64);
        $bundlePath = $this->writeBundle($bundle);
        $reportPath = $this->ephemeralPath('replay-verify-out');

        $this->artisan('workflow:v2:replay-verify', [
            'bundle' => $bundlePath,
            '--skip-replay' => true,
            '--output' => $reportPath,
        ])->assertFailed();

        $report = $this->readJson($reportPath);
        $this->assertSame('failed', $report['verdict']);
        $this->assertContains(
            'integrity.checksum_mismatch',
            array_column($report['integrity']['findings'], 'rule'),
        );
    }

    public function testStrictWarningsTreatsWarningsAsFailures(): void
    {
        $bundle = self::wellFormedBundle();
        $bundle['commands'][] = [
            'id' => 'cmd-orphan',
            'sequence' => 9,
            'type' => 'workflow.signal',
            'status' => 'applied',
            'outcome' => 'applied',
            'applied_at' => '2026-04-09T12:00:30.000000Z',
        ];
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $bundlePath = $this->writeBundle($bundle);
        $reportPath = $this->ephemeralPath('replay-verify-out');

        $this->artisan('workflow:v2:replay-verify', [
            'bundle' => $bundlePath,
            '--skip-replay' => true,
            '--strict-warnings' => true,
            '--output' => $reportPath,
        ])->assertFailed();

        $report = $this->readJson($reportPath);
        $this->assertSame('warning', $report['verdict']);
    }

    public function testCommandFailsWhenBundleFileMissing(): void
    {
        $reportPath = $this->ephemeralPath('replay-verify-out');

        $this->artisan('workflow:v2:replay-verify', [
            'bundle' => '/nonexistent/path/' . Str::ulid() . '.json',
            '--output' => $reportPath,
        ])->assertFailed();

        $report = $this->readJson($reportPath);
        $this->assertSame('failed', $report['verdict']);
        $this->assertNull($report['integrity']);
    }

    public function testJsonReportEmittedToStdoutWhenJsonFlagSet(): void
    {
        $bundle = self::wellFormedBundle();
        $bundlePath = $this->writeBundle($bundle);

        $this->artisan('workflow:v2:replay-verify', [
            'bundle' => $bundlePath,
            '--skip-replay' => true,
            '--json' => true,
        ])
            ->expectsOutputToContain('"verdict": "ok"')
            ->assertSuccessful();
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function writeBundle(array $bundle): string
    {
        $path = $this->ephemeralPath('replay-verify-bundle');
        file_put_contents($path, json_encode($bundle, JSON_THROW_ON_ERROR));

        return $path;
    }

    private function ephemeralPath(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . '-' . Str::ulid() . '.json';
        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (is_file($path)) {
                unlink($path);
            }
        });

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
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
            'dedupe_key' => 'cli-test-run:2:2026-04-09T12:00:00.000000Z',
            'history_complete' => true,
            'workflow' => [
                'instance_id' => 'cli-test-instance',
                'run_id' => 'cli-test-run',
                'run_number' => 1,
                'workflow_type' => 'verifier.cli.test',
                'workflow_class' => 'Tests\\Fixtures\\Cli',
                'status' => 'completed',
                'last_history_sequence' => 2,
            ],
            'payloads' => [
                'codec' => 'workflow-serializer',
                'arguments' => ['available' => false, 'data' => null],
                'output' => ['available' => false, 'data' => null],
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
            'links' => ['projection_source' => 'rebuilt', 'parents' => [], 'children' => []],
            'redaction' => ['applied' => false, 'policy' => null, 'paths' => []],
            'codec_schemas' => [],
            'payload_manifest' => ['version' => 1, 'entries' => []],
            'summary' => ['history_event_count' => 2],
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
