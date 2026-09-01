<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CiWorkflowPolicyTest extends TestCase
{
    private const WORKFLOW_PATH = __DIR__ . '/../../.github/workflows/php.yml';

    private const COPILOT_WORKFLOW_PATH = __DIR__ . '/../../.github/workflows/copilot-setup-steps.yml';

    public function testCopilotSetupUsesTheSupportedBranchAndToolchainContract(): void
    {
        $workflow = self::copilotWorkflow();
        $events = strstr($workflow, "\njobs:", true);

        self::assertIsString($events);
        self::assertMatchesRegularExpression(
            "/^  workflow_dispatch:\\R  push:\\R    branches: \\['1\\.x'\\]\\R    paths:\\R" .
            '      - \.github\/workflows\/copilot-setup-steps\.yml$/m',
            $events
        );
        self::assertMatchesRegularExpression(
            '/^  pull_request:\R    paths:\R      - \.github\/workflows\/copilot-setup-steps\.yml$/m',
            $events
        );

        $job = self::job('copilot-setup-steps', $workflow);

        self::assertStringContainsString('php-version: "8.3"', $job);
        self::assertStringContainsString('tools: composer:v2', $job);
        self::assertStringContainsString(
            'composer install --prefer-dist --no-progress --no-interaction --no-blocking',
            $job
        );
    }

    public function testCopilotSetupUsesPortableServiceEndpointsWithBoundedReadiness(): void
    {
        $workflow = self::copilotWorkflow();
        $job = self::job('copilot-setup-steps', $workflow);
        $resolver = self::step('Resolve Copilot service endpoints', $workflow);

        foreach ([
            'mysql' => [3306, 'MYSQL'],
            'postgres' => [5432, 'POSTGRES'],
            'redis' => [6379, 'REDIS'],
        ] as $service => [$port, $variablePrefix]) {
            self::assertStringContainsString("- {$port}/tcp", $job);
            self::assertStringContainsString(
                "{$variablePrefix}_PUBLISHED_PORT: \${{ job.services.{$service}.ports[{$port}] }}",
                $resolver
            );
            self::assertStringContainsString(
                "resolve_service {$service} {$port} \"\${$variablePrefix}_PUBLISHED_PORT\" {$variablePrefix}",
                $resolver
            );
        }

        self::assertStringContainsString('if getent hosts "$service_host"', $resolver);
        self::assertStringContainsString('resolved_host="$service_host"', $resolver);
        self::assertStringContainsString('resolved_host="127.0.0.1"', $resolver);
        self::assertStringContainsString(
            'echo "${variable_prefix}_HOST=${resolved_host}" >> "$GITHUB_ENV"',
            $resolver
        );
        self::assertStringContainsString(
            'echo "${variable_prefix}_PORT=${resolved_port}" >> "$GITHUB_ENV"',
            $resolver
        );

        $readiness = self::step('Wait for Copilot services', $workflow);

        self::assertStringContainsString('timeout-minutes: 2', $readiness);
        self::assertStringContainsString('for attempt in $(seq 1 20)', $readiness);
        self::assertStringContainsString('timeout 3 php -r', $readiness);
        self::assertStringContainsString('$database->query("SELECT 1")', $readiness);
        self::assertStringContainsString('PING', $readiness);
        self::assertStringContainsString(
            'wait_for MySQL "$MYSQL_HOST:$MYSQL_PORT" probe_database mysql',
            $readiness
        );
        self::assertStringContainsString(
            'wait_for PostgreSQL "$POSTGRES_HOST:$POSTGRES_PORT" probe_database pgsql',
            $readiness
        );
        self::assertStringContainsString('wait_for Redis "$REDIS_HOST:$REDIS_PORT" probe_redis', $readiness);

        $readinessPosition = strpos($job, '- name: Wait for Copilot services');

        self::assertIsInt($readinessPosition);
        foreach ([
            'Smoke test feature suite (MySQL + Redis)',
            'Smoke test feature suite (PostgreSQL + Redis)',
        ] as $smokeStep) {
            $smokePosition = strpos($job, "- name: {$smokeStep}");

            self::assertIsInt($smokePosition);
            self::assertLessThan($smokePosition, $readinessPosition);
        }

        self::assertStringContainsString(
            'export DB_HOST="$MYSQL_HOST"',
            self::step('Smoke test feature suite (MySQL + Redis)', $workflow)
        );
        self::assertStringContainsString(
            'export DB_HOST="$POSTGRES_HOST"',
            self::step('Smoke test feature suite (PostgreSQL + Redis)', $workflow)
        );
        self::assertStringNotContainsString('DB_HOST: 127.0.0.1', $job);
        self::assertStringNotContainsString('REDIS_HOST: 127.0.0.1', $job);
    }

    public function testReadmeOnlyPullRequestsAreIgnoredWithoutWeakeningPushQualification(): void
    {
        $events = strstr(self::workflow(), "\njobs:", true);

        self::assertIsString($events);
        self::assertMatchesRegularExpression("/^  push:\\R    branches: \\['1\\.x'\\]$/m", $events);

        $push = strstr($events, '  pull_request:', true);

        self::assertIsString($push);
        self::assertStringNotContainsString('paths-ignore:', $push);
        self::assertMatchesRegularExpression(
            "/^  pull_request:\\R    branches: \\['1\\.x'\\]\\R    paths-ignore:\\R      - README\\.md\\R\\z/m",
            $events
        );
    }

    public function testDatabaseJobsResolvePublishedOrServiceNetworkEndpoints(): void
    {
        $dependencyInjection = self::job('dependency-injection-compatibility');

        self::assertStringContainsString(
            'REDIS_PUBLISHED_PORT: ${{ job.services.redis.ports[6379] }}',
            $dependencyInjection
        );
        self::assertStringContainsString('if getent hosts redis', $dependencyInjection);
        self::assertStringContainsString('redis_host="redis"', $dependencyInjection);
        self::assertStringContainsString('redis_port="6379"', $dependencyInjection);
        self::assertStringContainsString('elif [ -n "$REDIS_PUBLISHED_PORT" ]', $dependencyInjection);
        self::assertStringContainsString('redis_host="127.0.0.1"', $dependencyInjection);
        self::assertStringContainsString('redis_port="$REDIS_PUBLISHED_PORT"', $dependencyInjection);
        self::assertStringContainsString('probe_redis', $dependencyInjection);
        self::assertStringContainsString('$redis_host:$redis_port after 20 attempts', $dependencyInjection);

        $build = self::job('build');

        self::assertStringContainsString('REDIS_PUBLISHED_PORT: ${{ job.services.redis.ports[6379] }}', $build);
        self::assertStringContainsString('if getent hosts redis', $build);
        self::assertStringContainsString('redis_host="127.0.0.1"', $build);
        self::assertStringContainsString('- 6379/tcp', $build);
        self::assertStringContainsString('echo "REDIS_HOST=${redis_host}" >> "$GITHUB_ENV"', $build);
        self::assertStringContainsString('echo "REDIS_PORT=${redis_port}" >> "$GITHUB_ENV"', $build);

        foreach ([
            'feature-mysql' => ['mysql', 3306, 'MYSQL', 'mysql'],
            'feature-postgresql' => ['postgres', 5432, 'POSTGRES', 'pgsql'],
        ] as $jobName => [$databaseService, $databasePort, $variablePrefix, $connection]) {
            $job = self::job($jobName);

            self::assertStringContainsString("{$databaseService}:\n        image: {$databaseService}", $job);
            self::assertStringContainsString("- {$databasePort}/tcp", $job);
            self::assertStringContainsString('redis:', $job);
            self::assertStringContainsString('- 6379/tcp', $job);
            self::assertStringContainsString('if getent hosts "$service_host"', $job);
            self::assertStringContainsString('resolved_host="127.0.0.1"', $job);
            self::assertStringContainsString(
                'echo "${variable_prefix}_HOST=${resolved_host}" >> "$GITHUB_ENV"',
                $job
            );
            self::assertStringContainsString(
                'echo "${variable_prefix}_PORT=${resolved_port}" >> "$GITHUB_ENV"',
                $job
            );
            self::assertStringContainsString(
                "resolve_service {$databaseService} {$databasePort} \"\${$variablePrefix}_PUBLISHED_PORT\" DB",
                $job
            );
            self::assertStringContainsString('resolve_service redis 6379 "$REDIS_PUBLISHED_PORT" REDIS', $job);
            self::assertSame(2, substr_count($job, "DB_CONNECTION: {$connection}"));
            self::assertSame(2, substr_count($job, 'QUEUE_CONNECTION: redis'));
        }

        self::assertStringContainsString(
            'MYSQL_PUBLISHED_PORT: ${{ job.services.mysql.ports[3306] }}',
            self::job('feature-mysql')
        );
        self::assertStringContainsString(
            'POSTGRES_PUBLISHED_PORT: ${{ job.services.postgres.ports[5432] }}',
            self::job('feature-postgresql')
        );
        self::assertStringContainsString(
            'REDIS_PUBLISHED_PORT: ${{ job.services.redis.ports[6379] }}',
            self::workflow()
        );
        self::assertStringContainsString(
            'mysql -e \'CREATE DATABASE testbench\' -h"$DB_HOST" -uroot -ppassword -P "$DB_PORT"',
            self::step('Create MySQL database')
        );
        self::assertStringContainsString('POSTGRES_DB: testbench', self::job('feature-postgresql'));
    }

    public function testServicesHaveBoundedReadinessChecks(): void
    {
        $unitRedis = self::step('Wait for unit Redis service');

        self::assertStringContainsString('timeout-minutes: 2', $unitRedis);
        self::assertStringContainsString('for attempt in $(seq 1 20)', $unitRedis);
        self::assertStringContainsString(
            'Redis was not ready at $REDIS_HOST:$REDIS_PORT after 20 attempts',
            $unitRedis
        );

        foreach (['MySQL', 'PostgreSQL'] as $database) {
            $readiness = self::step("Wait for {$database} and Redis");

            self::assertStringContainsString('timeout-minutes: 2', $readiness);
            self::assertStringContainsString('for attempt in $(seq 1 20)', $readiness);
            self::assertStringContainsString('timeout 3 php -r', $readiness);
            self::assertStringContainsString('$database->query("SELECT 1")', $readiness);
            self::assertStringContainsString('probe_redis', $readiness);
            self::assertStringContainsString('PING', $readiness);
            self::assertStringContainsString("wait_for {$database} \"\$DB_HOST:\$DB_PORT\" probe_database", $readiness);
            self::assertStringContainsString('wait_for Redis "$REDIS_HOST:$REDIS_PORT" probe_redis', $readiness);
        }

        $mysql = self::job('feature-mysql');
        $readinessPosition = strpos($mysql, '- name: Wait for MySQL and Redis');
        $creationPosition = strpos($mysql, '- name: Create MySQL database');

        self::assertIsInt($readinessPosition);
        self::assertIsInt($creationPosition);
        self::assertLessThan($creationPosition, $readinessPosition);
    }

    public function testReviewAndPushQualificationsHaveIndependentBounds(): void
    {
        foreach ([
            'MySQL' => 'feature-mysql',
            'PostgreSQL' => 'feature-postgresql',
        ] as $database => $jobName) {
            $full = self::step("Run full {$database} feature suite");
            $review = self::step("Run bounded {$database} review smoke");

            self::assertStringContainsString('timeout-minutes: 15', self::job($jobName));
            self::assertStringContainsString("if: github.event_name == 'push'", $full);
            self::assertStringContainsString('timeout-minutes: 12', $full);
            self::assertStringContainsString('--testsuite feature', $full);
            self::assertStringContainsString("if: github.event_name == 'pull_request'", $review);
            self::assertStringContainsString('timeout-minutes: 3', $review);
            self::assertStringContainsString('tests/Feature/AsyncWorkflowTest.php', $review);
        }

        self::assertStringContainsString("if: github.event_name == 'pull_request'", self::step('Run unit test suite'));
        self::assertStringContainsString("if: github.event_name == 'push'", self::step('Code Coverage'));
    }

    public function testComposerIsProvisionedWithoutMutatingTheSystemExecutable(): void
    {
        foreach (['build', 'feature-mysql', 'feature-postgresql'] as $jobName) {
            $job = self::job($jobName);

            self::assertStringContainsString('- name: Set up PHP and Composer', $job);
            self::assertMatchesRegularExpression('/uses: shivammathur\/setup-php@[0-9a-f]{40} # v2/', $job);
            self::assertStringContainsString('tools: composer:v2', $job);
            self::assertStringNotContainsString('composer self-update', $job);
            self::assertStringNotContainsString('sudo ', $job);
        }
    }

    private static function job(string $name, ?string $workflow = null): string
    {
        $matched = preg_match(
            '/^  ' . preg_quote($name, '/') . ':\R(?<job>.*?)(?=^  [a-zA-Z0-9_-]+:|\z)/ms',
            $workflow ?? self::workflow(),
            $matches
        );

        self::assertSame(1, $matched, "Missing {$name} workflow job.");

        return $matches['job'];
    }

    private static function step(string $name, ?string $workflow = null): string
    {
        $matched = preg_match(
            '/^(?<indent> +)- name: ' . preg_quote($name, '/') .
            '\R(?<step>.*?)(?=^(?P=indent)- name:|\z)/ms',
            $workflow ?? self::workflow(),
            $matches
        );

        self::assertSame(1, $matched, "Missing {$name} workflow step.");

        return $matches['step'];
    }

    private static function workflow(): string
    {
        $workflow = file_get_contents(self::WORKFLOW_PATH);

        self::assertIsString($workflow);

        return $workflow;
    }

    private static function copilotWorkflow(): string
    {
        $workflow = file_get_contents(self::COPILOT_WORKFLOW_PATH);

        self::assertIsString($workflow);

        return $workflow;
    }
}
