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

        $this->assertSame(['9'], array_map('strval', array_keys($policy['removed_majors'])));
        $this->assertSame('2.16', $policy['removed_majors']['9']['last_workflow_minor']);

        // 9.52.23 is the newest Laravel 9 release; its TokenGuard is unfixed.
        foreach (['9.0.0', '9.52.17', '9.52.23', '9.99.99'] as $version) {
            $this->assertFalse(Semver::satisfies($version, $constraint), "{$version} must be refused");
        }
        foreach (['10.50.3', '11.56.0', '12.68.9', '13.29.9'] as $version) {
            $this->assertFalse(Semver::satisfies($version, $constraint), "{$version} is below its floor");
        }
        foreach ($policy['supported_majors'] as $major) {
            $this->assertTrue(Semver::satisfies($major['minimum'], $constraint), $major['minimum']);
        }

        $this->assertArrayNotHasKey('9.*', $upgrade['supported_intersection']['authority']['laravel_minimum_php']);
        $this->assertNotContains('9.*', array_column($upgrade['supported_intersection']['cells'], 'laravel'));
        foreach ($policy['accepted_advisories'] as $advisory) {
            $this->assertNotContains('9', $advisory['affected_supported_majors']);
        }
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
