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

    public function testServiceEndpointsSupportPublishedAndServiceNetworkPorts(): void
    {
        $workflow = self::workflow();

        self::assertStringContainsString('if getent hosts "$service_host"', $workflow);
        self::assertStringContainsString('resolved_host="127.0.0.1"', $workflow);
        self::assertStringContainsString(
            'echo "${variable_prefix}_HOST=${resolved_host}" >> "$GITHUB_ENV"',
            $workflow
        );
        self::assertStringContainsString(
            'echo "${variable_prefix}_PORT=${resolved_port}" >> "$GITHUB_ENV"',
            $workflow
        );

        foreach ([
            'MYSQL' => ['mysql', 3306],
            'POSTGRES' => ['postgres', 5432],
            'REDIS' => ['redis', 6379],
        ] as $prefix => [$service, $port]) {
            self::assertStringContainsString(
                "{$prefix}_PUBLISHED_PORT: \${{ job.services.{$service}.ports[{$port}] }}",
                $workflow
            );
            self::assertStringContainsString(
                "resolve_service {$prefix} {$service} {$port} \"\${$prefix}_PUBLISHED_PORT\"",
                $workflow
            );
        }

        self::assertStringContainsString(
            'mysql -e \'CREATE DATABASE testbench\' -h"$MYSQL_HOST" -uroot -ppassword -P "$MYSQL_PORT"',
            self::step('Create databases')
        );

        foreach ([
            'MySQL' => ['MYSQL', 'mysql'],
            'PostgreSQL' => ['POSTGRES', 'pgsql'],
        ] as $database => [$prefix, $connection]) {
            $step = self::step("Run test suite ({$database})");

            self::assertStringContainsString("DB_HOST=\"\${$prefix}_HOST\" DB_PORT=\"\${$prefix}_PORT\"", $step);
            self::assertStringContainsString('REDIS_HOST="$REDIS_HOST" REDIS_PORT="$REDIS_PORT"', $step);
            self::assertStringContainsString("DB_CONNECTION: {$connection}", $step);
            self::assertStringContainsString('QUEUE_CONNECTION: redis', $step);
        }
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
