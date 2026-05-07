<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;

final class LocalActivitiesDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/local-activities.md';

    private const REQUIRED_PHRASES = [
        '## Authoring API',
        'localActivity(',
        'Workflow::localActivity',
        'Workflow::executeLocalActivity',
        'LocalActivityOptions',
        'execution_mode=local',
        'local_activity=true',
        'No `TaskType::Activity` workflow task is created',
        'workflow task lease',
        'startToCloseTimeout',
        'scheduleToCloseTimeout',
        'heartbeatTimeout',
        'retry_reason=cold_replay',
        'ActivityRetryScheduled',
        'ActivityTimedOut',
        'ActivityCancelled',
        'Routing And Admission',
        'OperatorMetrics.activities.local',
        'HistoryExport',
        'Use ordinary activities',
        'Use `sideEffect(...)` only',
    ];

    public function testLocalActivitiesContractCoversRuntimeAndAuthoringSurfaces(): void
    {
        $contents = $this->documentContents();

        foreach (self::REQUIRED_PHRASES as $phrase) {
            $this->assertStringContainsString(
                $phrase,
                $contents,
                sprintf('Local activities contract must document [%s].', $phrase),
            );
        }
    }

    private function documentContents(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::DOCUMENT;
        $contents = file_get_contents($path);

        $this->assertIsString($contents, sprintf('Could not read %s.', self::DOCUMENT));

        return $contents;
    }
}
