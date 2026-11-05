<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Composer\Semver\Semver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

final class LaravelDependencySecurityPolicyTest extends TestCase
{
    public function testPolicyOwnsTheComposerAndCompatibilityConstraints(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = json_decode(
            (string) file_get_contents($root . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $policyPath = $composer['extra']['durable-workflow']['laravel-dependency-security-policy'];
        $policy = json_decode(
            (string) file_get_contents($root . '/' . $policyPath),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $upgrade = json_decode(
            (string) file_get_contents($root . '/resources/laravel-embedded-upgrade-contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $constraint = implode('|', array_map(
            static fn (array $major): string => '^' . $major['minimum'],
            $policy['supported_majors'],
        ));

        $this->assertSame('durable-workflow.laravel-dependency-security-policy', $policy['schema']);
        $this->assertSame('laravel/framework', $policy['package']);
        $this->assertSame($constraint, $composer['require']['laravel/framework']);
        $this->assertSame($constraint, $upgrade['supported_intersection']['authority']['embedded_v2']['laravel']);
        $this->assertSame($policyPath, $upgrade['qualification']['dependency_security_policy']);
        $this->assertSame(
            array_map(static fn ($major): string => $major . '.*', array_keys($policy['supported_majors'])),
            array_keys($upgrade['supported_intersection']['authority']['laravel_minimum_php']),
        );
    }

    public function testTheComposerConstraintRefusesRemovedLaravelMajors(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = json_decode(
            (string) file_get_contents($root . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $policy = json_decode(
            (string) file_get_contents($root . '/resources/laravel-dependency-security-policy.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $upgrade = json_decode(
            (string) file_get_contents($root . '/resources/laravel-embedded-upgrade-contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $constraint = $composer['require']['laravel/framework'];

        $this->assertSame(['9', '10', '11'], array_map('strval', array_keys($policy['removed_majors'])));
        $this->assertSame('2.16', $policy['removed_majors']['9']['last_workflow_minor']);
        $this->assertSame('2.20', $policy['removed_majors']['10']['last_workflow_minor']);
        $this->assertSame('2.20', $policy['removed_majors']['11']['last_workflow_minor']);

        // 9.52.23 is the newest Laravel 9 release; its TokenGuard is unfixed.
        foreach ([
            '9.0.0', '9.52.17', '9.52.23', '9.99.99',
            '10.50.4', '10.99.99', '11.56.1', '11.99.99',
        ] as $version) {
            $this->assertFalse(Semver::satisfies($version, $constraint), "{$version} must be refused");
        }
        foreach (['12.68.9', '13.29.9'] as $version) {
            $this->assertFalse(Semver::satisfies($version, $constraint), "{$version} is below its floor");
        }
        foreach ($policy['supported_majors'] as $major) {
            $this->assertTrue(Semver::satisfies($major['minimum'], $constraint), $major['minimum']);
        }

        foreach (['9.*', '10.*', '11.*'] as $removed) {
            $this->assertArrayNotHasKey(
                $removed,
                $upgrade['supported_intersection']['authority']['laravel_minimum_php']
            );
            $this->assertNotContains($removed, array_column($upgrade['supported_intersection']['cells'], 'laravel'));
        }
        $this->assertSame([], $policy['accepted_advisories']);
    }

    public function testTheEmbeddedPhpFloorPreservesTheStableV1Authority(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = json_decode(
            (string) file_get_contents($root . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $upgrade = json_decode(
            (string) file_get_contents($root . '/resources/laravel-embedded-upgrade-contract.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $authority = $upgrade['supported_intersection']['authority'];

        foreach (['8.1.0', '8.1.33', '8.1.99'] as $version) {
            $this->assertFalse(Semver::satisfies($version, $composer['require']['php']));
        }
        foreach (['8.2.0', '8.3.0', '8.4.0'] as $version) {
            $this->assertTrue(Semver::satisfies($version, $composer['require']['php']));
        }
        $this->assertSame([
            'php' => '^8.1',
            'laravel' => '^9.0|^10.0|^11.0|^12.0|^13.0',
        ], $authority['stable_v1']);
        $this->assertSame(['8.2', '8.3', '8.4'], $authority['qualified_php_minors']);
        $this->assertSame([
            [
                'php' => '8.2',
                'laravel' => '12.*',
            ],
            [
                'php' => '8.3',
                'laravel' => '13.*',
            ],
        ], array_map(
            static fn (string $laravel, string $php): array => [
                'php' => $php,
                'laravel' => $laravel,
            ],
            array_keys($authority['laravel_minimum_php']),
            array_values($authority['laravel_minimum_php']),
        ));
    }

    public function testRootComposerDependabotUpdatesCannotBeConfigured(): void
    {
        $root = dirname(__DIR__, 3);
        $dependabotPath = $root . '/.github/dependabot.yml';

        if (is_file($dependabotPath)) {
            $dependabot = Yaml::parseFile($dependabotPath);

            foreach ($dependabot['updates'] ?? [] as $update) {
                if (($update['package-ecosystem'] ?? null) !== 'composer') {
                    continue;
                }

                $directories = isset($update['directories'])
                    ? $update['directories']
                    : [$update['directory'] ?? null];

                $this->assertNotContains(
                    '/',
                    $directories,
                    'Dependabot cannot update the unlocked root Composer library.',
                );
            }
        }

        $workflow = Yaml::parseFile($root . '/.github/workflows/dependency-security.yml');
        $steps = $workflow['jobs']['laravel-supported-majors']['steps'];
        $commands = array_column($steps, 'run');

        $this->assertContains('.github/dependabot.yml', $workflow['on']['push']['paths']);
        $this->assertContains('.github/dependabot.yml', $workflow['on']['pull_request']['paths']);
        $this->assertContains('php scripts/ci/check-laravel-dependency-security.php --policy-only', $commands);
        $this->assertContains('php scripts/ci/check-laravel-dependency-security.php', $commands);
    }

    public function testPolicyOnlyMonitorValidationPassesWithoutAConsumerLockfile(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([
            PHP_BINARY,
            $root . '/scripts/ci/check-laravel-dependency-security.php',
            '--policy-only',
        ]);
        $process->mustRun();

        $this->assertStringContainsString('internally consistent', $process->getOutput());
    }
}
