<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Workflow\V2\Support\LaravelEmbeddedUpgradeContract;

final class LaravelEmbeddedUpgradeContractTest extends TestCase
{
    public function testPackageManifestPublishesTheSupportedLaravelTransition(): void
    {
        $manifest = LaravelEmbeddedUpgradeContract::manifest();

        $this->assertSame(LaravelEmbeddedUpgradeContract::SCHEMA, $manifest['schema']);
        $this->assertSame(LaravelEmbeddedUpgradeContract::VERSION, $manifest['version']);
        $this->assertSame('durable-workflow/workflow', $manifest['transition']['from']['package']);
        $this->assertSame('durable-workflow/workflow', $manifest['transition']['to']['package']);
        $this->assertSame('composer_replace_only', $manifest['transition']['legacy_package_alias']['role']);
        $this->assertArrayNotHasKey('stable_v1_framework_bootstrap', $manifest['transition']);
        $this->assertFalse($manifest['transition']['requires_service_mode']);
        $this->assertFalse($manifest['state_ownership']['composer_change_migrates_history']);
        $this->assertFalse($manifest['state_ownership']['v1']['reinterpret_as_v2']);
        $this->assertFalse($manifest['state_ownership']['embedded_v2']['accepts_v1_history']);
        $this->assertSame('workflows.v2.queue', $manifest['strategies']['coexist']['v2_queue_config']);
        $this->assertTrue($manifest['laravel_experience']['constructor_injection']);
        $this->assertTrue($manifest['laravel_experience']['straight_line_authoring']);
        $this->assertFalse($manifest['qualification']['application_bootstrap_patch_allowed']);

        $this->assertSame(
            $this->deriveSupportedCells($manifest['supported_intersection']['authority']),
            $manifest['supported_intersection']['cells'],
        );
    }

    public function testComposerMetadataExposesTheShippedContract(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $relativePath = $composer['extra']['durable-workflow']['laravel-embedded-upgrade-contract'];

        $this->assertSame('resources/laravel-embedded-upgrade-contract.json', $relativePath);
        $this->assertFileExists(dirname(__DIR__, 3) . '/' . $relativePath);

        $authority = LaravelEmbeddedUpgradeContract::manifest()['supported_intersection']['authority'];
        $this->assertSame($composer['require']['php'], $authority['embedded_v2']['php']);
        $this->assertSame($composer['require']['laravel/framework'], $authority['embedded_v2']['laravel']);
    }

    public function testQualificationMatricesAreDerivedFromTheShippedContract(): void
    {
        $root = dirname(__DIR__, 3);
        $intersection = LaravelEmbeddedUpgradeContract::manifest()['supported_intersection'];
        $sourceMatrix = $this->qualificationMatrix($root, 'source');
        $publishedMatrix = $this->qualificationMatrix($root, 'published');
        $sourceWorkflow = Yaml::parseFile($root . '/.github/workflows/php.yml');
        $releaseWorkflow = Yaml::parseFile($root . '/.github/workflows/release-docs-audit.yml');
        $expectedSourceMatrix = [
            'include' => $this->deriveMinimumCells($intersection['authority']),
        ];
        $expectedPublishedMatrix = [
            'include' => $intersection['cells'],
        ];

        $this->assertSame($expectedSourceMatrix, $sourceMatrix);
        $this->assertSame($expectedPublishedMatrix, $publishedMatrix);
        $this->assertSame(
            '${{ steps.laravel-matrix.outputs.matrix }}',
            $sourceWorkflow['jobs']['preflight']['outputs']['laravel_source_matrix'],
        );
        $this->assertSame(
            '${{ fromJSON(needs.preflight.outputs.laravel_source_matrix) }}',
            $sourceWorkflow['jobs']['laravel-embedded-upgrade-source']['strategy']['matrix'],
        );
        $this->assertSame(
            '${{ steps.laravel-matrix.outputs.matrix }}',
            $releaseWorkflow['jobs']['release-artifact']['outputs']['laravel_published_matrix'],
        );
        $this->assertSame(
            '${{ fromJSON(needs.release-artifact.outputs.laravel_published_matrix) }}',
            $releaseWorkflow['jobs']['laravel-embedded-upgrade-published']['strategy']['matrix'],
        );
        $this->assertSame('release-artifact', $releaseWorkflow['jobs']['docs-release-audit']['needs']);
        $this->assertSame(
            'release-artifact',
            $releaseWorkflow['jobs']['laravel-embedded-upgrade-published']['needs'],
        );
    }

    /**
     * @return array{include: list<array{php: string, laravel: string}>}
     */
    private function qualificationMatrix(string $root, string $scope): array
    {
        $process = new Process([
            PHP_BINARY,
            $root . '/scripts/ci/laravel-embedded-upgrade-matrix.php',
            '--scope=' . $scope,
        ]);
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $authority
     * @return list<array{php: string, laravel: string}>
     */
    private function deriveSupportedCells(array $authority): array
    {
        $cells = [];

        foreach ($authority['qualified_php_minors'] as $php) {
            foreach ($authority['laravel_minimum_php'] as $laravel => $minimumPhp) {
                if (version_compare($php, $minimumPhp, '>=')) {
                    $cells[] = [
                        'php' => $php,
                        'laravel' => $laravel,
                    ];
                }
            }
        }

        return $cells;
    }

    /**
     * @param array<string, mixed> $authority
     * @return list<array{php: string, laravel: string}>
     */
    private function deriveMinimumCells(array $authority): array
    {
        $cells = [];

        foreach ($authority['laravel_minimum_php'] as $laravel => $php) {
            $cells[] = [
                'php' => $php,
                'laravel' => $laravel,
            ];
        }

        return $cells;
    }
}
