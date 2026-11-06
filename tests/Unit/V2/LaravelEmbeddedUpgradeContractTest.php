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
        $releaseWorkflow = Yaml::parseFile($root . '/.github/workflows/release-verification.yml');
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
            $releaseWorkflow['jobs']['published-package']['outputs']['laravel_published_matrix'],
        );
        $this->assertSame(
            '${{ fromJSON(needs.published-package.outputs.laravel_published_matrix) }}',
            $releaseWorkflow['jobs']['laravel-upgrade']['strategy']['matrix'],
        );
        $this->assertSame('published-package', $releaseWorkflow['jobs']['laravel-upgrade']['needs']);
    }

    public function testPublishedMatrixUsesTheExplicitProductContract(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'workflow-tag-contract-');
        $this->assertNotFalse($path);
        $cells = [['php' => '8.2', 'laravel' => '11'], ['php' => '8.4', 'laravel' => '13']];

        try {
            file_put_contents($path, json_encode(['supported_intersection' => ['cells' => $cells]], JSON_THROW_ON_ERROR));
            $process = $this->matrixProcess(['--scope=published', '--contract-path=' . $path]);
            $process->mustRun();

            $this->assertSame(['include' => $cells], json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            unlink($path);
        }
    }

    public function testMissingOrOversizedProductContractsNeverFallBackToTheHarness(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'workflow-invalid-contract-');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, str_repeat(' ', 65_537));
            $oversized = $this->matrixProcess(['--scope=published', '--contract-path=' . $path]);
            $this->assertSame(1, $oversized->run());
            $this->assertSame('', $oversized->getOutput());

            $missing = $this->matrixProcess(['--scope=published', '--contract-path=' . $path . '.missing']);
            $this->assertSame(1, $missing->run());
            $this->assertSame('', $missing->getOutput());
        } finally {
            unlink($path);
        }
    }

    public function testMatrixInputRefusesSymlinksAndAmbiguousArguments(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'workflow-contract-link-');
        $this->assertNotFalse($path);
        $link = $path . '.link';

        try {
            symlink($path, $link);
            foreach ([['--scope=published', '--contract-path=' . $link],
                ['--scope=published', '--contract-path=', '--contract-path=' . $path],
                ['--scope=published', '--scope=source'], ['--scope=published', '--unknown=ignored']] as $arguments) {
                $process = $this->matrixProcess($arguments);
                $this->assertSame(1, $process->run());
                $this->assertSame('', $process->getOutput());
            }
        } finally {
            unlink($link);
            unlink($path);
        }
    }

    /** @param list<string> $arguments */
    private function matrixProcess(array $arguments): Process
    {
        return new Process([PHP_BINARY, dirname(__DIR__, 3) . '/scripts/ci/laravel-embedded-upgrade-matrix.php', ...$arguments]);
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
