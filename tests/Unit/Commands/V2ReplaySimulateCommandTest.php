<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Str;
use Tests\TestCase;
use Workflow\V2\Support\HistoryExport;
use Workflow\V2\Support\ReplayVerification;

final class V2ReplaySimulateCommandTest extends TestCase
{
    public function testCommandAggregatesBundleDirectoryIntoSimulationReport(): void
    {
        $directory = $this->ephemeralDirectory('replay-simulate-bundles');
        $reportPath = $this->ephemeralPath('replay-simulate-out');

        $good = self::wellFormedBundle('run-good');
        $bad = self::wellFormedBundle('run-bad');
        $bad['integrity']['checksum'] = str_repeat('0', 64);

        $this->writeBundle($directory . '/good.json', $good);
        $this->writeBundle($directory . '/bad.json', $bad);

        $this->artisan('workflow:v2:replay-simulate', [
            'directory' => $directory,
            '--skip-replay' => true,
            '--output' => $reportPath,
        ])->assertFailed();

        $report = $this->readJson($reportPath);

        $this->assertSame(ReplayVerification::SIMULATION_REPORT_SCHEMA, $report['schema']);
        $this->assertSame('failed', $report['verdict']);
        $this->assertSame('block_and_investigate', $report['promotion_decision']);
        $this->assertSame(2, $report['summary']['total']);
        $this->assertSame(1, $report['summary']['ok']);
        $this->assertSame(1, $report['summary']['failed']);
        $this->assertSame(2, $report['evidence']['bundle_count']);
        $this->assertSame(0, $report['evidence']['missing_bundle_count']);
        $this->assertSame(2, $report['evidence']['integrity_checked_count']);
        $this->assertSame(0, $report['evidence']['replay_checked_count']);
        $this->assertTrue($report['evidence']['replay_skipped']);
        $this->assertCount(2, $report['bundles']);
        $this->assertTrue($report['bundles'][0]['evidence']['integrity_checked']);
        $this->assertTrue($report['bundles'][0]['evidence']['replay_skipped']);
        $this->assertSame([], $report['missing_bundles']);
    }

    public function testCommandFailsWhenDirectoryHasNoBundles(): void
    {
        $directory = $this->ephemeralDirectory('replay-simulate-empty');
        $reportPath = $this->ephemeralPath('replay-simulate-out');

        $this->artisan('workflow:v2:replay-simulate', [
            'directory' => $directory,
            '--output' => $reportPath,
        ])->assertFailed();

        $report = $this->readJson($reportPath);

        $this->assertSame('failed', $report['verdict']);
        $this->assertSame('block_and_investigate', $report['promotion_decision']);
        $this->assertSame(0, $report['summary']['total']);
        $this->assertSame(0, $report['evidence']['bundle_count']);
        $this->assertSame(1, $report['evidence']['missing_bundle_count']);
        $this->assertNotSame([], $report['missing_bundles']);
    }

    public function testStrictWarningsTreatsBundleWarningsAsFailures(): void
    {
        $directory = $this->ephemeralDirectory('replay-simulate-warning');
        $reportPath = $this->ephemeralPath('replay-simulate-out');

        $bundle = self::wellFormedBundle('run-warning');
        $bundle['commands'][] = [
            'id' => 'cmd-orphan',
            'sequence' => 9,
            'type' => 'workflow.signal',
            'status' => 'applied',
            'outcome' => 'applied',
            'applied_at' => '2026-04-09T12:00:30.000000Z',
        ];
        $bundle['integrity'] = self::buildIntegrity($bundle);

        $this->writeBundle($directory . '/warning.json', $bundle);

        $this->artisan('workflow:v2:replay-simulate', [
            'directory' => $directory,
            '--skip-replay' => true,
            '--strict-warnings' => true,
            '--output' => $reportPath,
        ])->assertFailed();

        $report = $this->readJson($reportPath);

        $this->assertSame('failed', $report['verdict']);
        $this->assertSame(1, $report['summary']['failed']);
        $this->assertTrue($report['bundles'][0]['strict_warning_failure']);
        $this->assertTrue($report['evidence']['strict_warnings']);
        $this->assertTrue($report['bundles'][0]['evidence']['strict_warnings']);
        $this->assertSame('warning', $report['bundles'][0]['integrity']['status']);
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function writeBundle(string $path, array $bundle): void
    {
        file_put_contents($path, json_encode($bundle, JSON_THROW_ON_ERROR));
    }

    private function ephemeralDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . '-' . Str::ulid();
        mkdir($path);

        $this->beforeApplicationDestroyed(static function () use ($path): void {
            if (! is_dir($path)) {
                return;
            }

            foreach (glob($path . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($path);
        });

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
    private static function wellFormedBundle(string $runId): array
    {
        $bundle = [
            'schema' => HistoryExport::SCHEMA,
            'schema_version' => HistoryExport::SCHEMA_VERSION,
            'exported_at' => '2026-04-09T12:05:00.000000Z',
            'dedupe_key' => "{$runId}:2:2026-04-09T12:00:00.000000Z",
            'history_complete' => true,
            'workflow' => [
                'instance_id' => "{$runId}-instance",
                'run_id' => $runId,
                'run_number' => 1,
                'workflow_type' => 'verifier.cli.test',
                'workflow_class' => 'Tests\\Fixtures\\Cli',
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
                    'id' => "{$runId}-evt-1",
                    'sequence' => 1,
                    'type' => 'WorkflowStarted',
                    'workflow_command_id' => "{$runId}-cmd-start",
                    'workflow_task_id' => null,
                    'recorded_at' => '2026-04-09T12:00:00.000000Z',
                    'payload' => [],
                ],
                [
                    'id' => "{$runId}-evt-2",
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
                    'id' => "{$runId}-cmd-start",
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
