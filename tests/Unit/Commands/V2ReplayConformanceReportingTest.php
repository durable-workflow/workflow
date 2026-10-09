<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class V2ReplayConformanceReportingTest extends TestCase
{
    public function testHumanSuccessReportsTheRuntimeShardAndEveryScenario(): void
    {
        $exit = Artisan::call('workflow:v2:replay-conformance', self::publishedTuple());
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString(
            'Workflow PHP replay conformance shard: PASS (16/16 scenarios passed)',
            $output
        );
        $this->assertStringContainsString('Outcome: pass', $output);
        $this->assertStringContainsString('Schema: durable-workflow.v2.replay-conformance.result', $output);
        $this->assertSame(16, substr_count($output, '[PASS]'));
        $this->assertStringContainsString('[PASS] published_artifact_install_only', $output);
        $this->assertStringContainsString('[PASS] php_completed_history_activity_replay', $output);
        $this->assertStringContainsString('[PASS] php_in_flight_signal_restart_timing', $output);
        $this->assertStringNotContainsString('[FAIL]', $output);
    }

    public function testHumanFailureSeparatesMissingArtifactEvidenceFromPassingReplay(): void
    {
        $exit = Artisan::call('workflow:v2:replay-conformance');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'Workflow PHP replay conformance shard: FAIL (15/16 scenarios passed)',
            $output
        );
        $this->assertStringContainsString('Outcome: fail', $output);
        $this->assertStringContainsString('[FAIL] published_artifact_install_only', $output);
        $this->assertSame(1, substr_count($output, '[FAIL]'));
        $this->assertSame(15, substr_count($output, '[PASS]'));
        $this->assertStringContainsString('[PASS] php_code_divergence_refusal', $output);
    }

    #[DataProvider('reportOutcomes')]
    public function testJsonStdoutIsOneCompleteReportWithAnHonestExit(bool $published): void
    {
        $options = $published ? self::publishedTuple() : [];
        $options['--json'] = true;

        $exit = Artisan::call('workflow:v2:replay-conformance', $options);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($published ? 0 : 1, $exit);
        $this->assertSame($published ? 'pass' : 'fail', $report['outcome']);
        $this->assertSame('durable-workflow.v2.replay-conformance.result', $report['schema']);
        $this->assertSame(1, $report['schema_version']);
        $this->assertSame('workflow-php-runtime-shard', $report['coverage_scope']);
        $this->assertSame(['workflow-php'], $report['runtime_matrix']['runtimes']);
        $this->assertCount(16, $report['scenario_results']);
        $this->assertSame(5, $report['completed_history_replay']['passed']);
        $this->assertSame(6, $report['worker_restart_replay']['passed']);
        $this->assertSame(3, $report['adversarial_replay']['passed']);
        $this->assertSame(1, $report['in_flight_timing']['passed']);
        $this->assertCount($published ? 0 : 1, $report['findings']);

        if ($published) {
            $this->assertSame([], $report['finding_links']);
        } else {
            $this->assertSame($report['findings'], $report['finding_links']['published_artifact_install_only']);
            $this->assertSame(
                ['server', 'cli', 'workflow-php', 'sdk-python', 'waterline'],
                $report['findings'][0]['evidence']['missing_artifacts'],
            );
        }
    }

    public function testOutputFileTakesPrecedenceOverJsonStdoutAndPreservesTheReport(): void
    {
        $directory = sys_get_temp_dir() . '/replay-conformance-report-' . Str::ulid();
        $path = $directory . '/nested/report.json';
        $this->beforeApplicationDestroyed(static function () use ($directory, $path): void {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($directory . '/nested')) {
                rmdir($directory . '/nested');
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        });
        $options = self::publishedTuple();
        $options['--output'] = '  ' . $path . '  ';
        $options['--json'] = true;

        $exit = Artisan::call('workflow:v2:replay-conformance', $options);

        $this->assertSame(0, $exit);
        $this->assertSame('', Artisan::output());
        $this->assertFileExists($path);
        $persisted = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        unset($options['--output']);
        $this->assertSame(0, Artisan::call('workflow:v2:replay-conformance', $options));
        $stdout = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        unset($persisted['started_at'], $persisted['finished_at'], $stdout['started_at'], $stdout['finished_at']);
        $this->assertSame($stdout, $persisted);
    }

    public function testMetadataNormalizesAliasesAndIgnoresIncompleteEntries(): void
    {
        $options = [
            '--artifact-version' => [
                'ignored-without-equals', '=empty-actor', 'workflow=  ', 'cli=',
                ' server = 0.2.169 ', 'cli=0.1.55', ' WORKFLOW_PHP = 2.0.0-alpha.172 ',
                ' PYTHON = 0.4.71 ', 'waterline=2.0.0-alpha.57', 'observer=example=version',
            ],
            '--artifact-source' => [
                'ignored-without-equals', '=empty-actor', 'workflow=  ', 'cli=',
                'server=docker_image', ' cli = official_install_script ',
                ' WORKFLOW = composer_package ', ' SDK_PYTHON = pypi_package ',
                'waterline=packagist_package', 'observer=example=source',
            ],
            '--json' => true,
        ];

        $this->assertSame(0, Artisan::call('workflow:v2:replay-conformance', $options));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('2.0.0-alpha.172', $report['artifact_versions']['workflow-php']);
        $this->assertSame('0.4.71', $report['artifact_versions']['sdk-python']);
        $this->assertSame('composer_package', $report['artifact_sources']['workflow-php']);
        $this->assertSame('pypi_package', $report['artifact_sources']['sdk-python']);
        $this->assertSame('example=version', $report['artifact_versions']['observer']);
        $this->assertSame('example=source', $report['artifact_sources']['observer']);
        $this->assertCount(6, $report['artifact_versions']);
        $this->assertCount(6, $report['artifact_sources']);
        $this->assertSame([], $report['findings']);
    }

    #[DataProvider('unpublishedVersions')]
    public function testAmbiguousVersionsFailWithLinkedEvidence(string $version, string $reason): void
    {
        $options = self::publishedTuple();
        $options['--artifact-version'][2] = 'workflow-php=' . $version;
        $options['--json'] = true;

        $this->assertSame(1, Artisan::call('workflow:v2:replay-conformance', $options));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $scenario = array_column($report['scenario_results'], null, 'scenario_id')['published_artifact_install_only'];
        $evidence = $scenario['observed_outputs'];
        $this->assertSame('fail', $report['outcome']);
        $this->assertSame('fail', $scenario['status']);
        $this->assertSame([
            'version' => $version,
            'reason' => $reason,
        ], $evidence['rejected_versions']['workflow-php']);
        $this->assertFalse($evidence['published_artifacts_only']);
        $this->assertFalse($evidence['published_install_tuple_proven']);
        $this->assertSame([], $evidence['missing_artifacts']);
        $this->assertCount(1, $report['findings']);
        $this->assertSame($evidence, $report['findings'][0]['evidence']);
        $this->assertSame($report['findings'], $report['finding_links']['published_artifact_install_only']);
        $this->assertSame(15, count(array_filter(
            $report['scenario_results'],
            static fn (array $row): bool => $row['status'] === 'pass',
        )));
    }

    public static function reportOutcomes(): array
    {
        return [
            'complete tuple' => [true],
            'missing tuple' => [false],
        ];
    }

    public static function unpublishedVersions(): array
    {
        return [
            'template' => ['<version>', 'placeholder_template'],
            'floating label' => ['latest', 'placeholder_label'],
            'wildcard' => ['2.*', 'wildcard_version'],
            'package self reference' => ['self.version', 'local_or_source_version'],
            'branch' => ['main', 'dev_or_branch_version'],
        ];
    }

    private static function publishedTuple(): array
    {
        return [
            '--artifact-version' => [
                'server=0.2.169', 'cli=0.1.55', 'workflow-php=2.0.0-alpha.172',
                'sdk-python=0.4.71', 'waterline=2.0.0-alpha.57',
            ],
            '--artifact-source' => [
                'server=docker_image', 'cli=official_install_script', 'workflow-php=composer_package',
                'sdk-python=pypi_package', 'waterline=packagist_package',
            ],
        ];
    }
}
