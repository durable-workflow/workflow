<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CiWorkflowPolicyTest extends TestCase
{
    private const WORKFLOW_PATH = __DIR__ . '/../../.github/workflows/php.yml';

    public function testReadmeOnlyPullRequestsAreIgnoredWithoutWeakeningPushQualification(): void
    {
        $events = strstr(self::workflow(), "\njobs:", true);

        self::assertIsString($events);
        self::assertMatchesRegularExpression('/^  push:\R    branches: \[ master \]$/m', $events);

        $push = strstr($events, '  pull_request:', true);

        self::assertIsString($push);
        self::assertStringNotContainsString('paths-ignore:', $push);
        self::assertMatchesRegularExpression(
            '/^  pull_request:\R    branches: \[ master \]\R    paths-ignore:\R      - README\.md\R\z/m',
            $events
        );
    }

    public function testDatabaseJobsResolvePublishedOrServiceNetworkEndpoints(): void
    {
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

    private static function job(string $name): string
    {
        $matched = preg_match(
            '/^  ' . preg_quote($name, '/') . ':\R(?<job>.*?)(?=^  [a-zA-Z0-9_-]+:|\z)/ms',
            self::workflow(),
            $matches
        );

        self::assertSame(1, $matched, "Missing {$name} workflow job.");

        return $matches['job'];
    }

    private static function step(string $name): string
    {
        $matched = preg_match(
            '/^    - name: ' . preg_quote($name, '/') . '\R(?<step>.*?)(?=^    - name:|\z)/ms',
            self::workflow(),
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
}
