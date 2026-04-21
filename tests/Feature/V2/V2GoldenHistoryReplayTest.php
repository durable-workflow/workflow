<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\V2\TestGoldenReplayWorkflow;
use Tests\TestCase;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Support\QueryStateReplayer;

final class V2GoldenHistoryReplayTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/../../Fixtures/V2/GoldenHistory';

    private const FIXTURE_SCHEMA = 'durable-workflow.golden-history.v1';

    private const REQUIRED_FAMILIES = [
        'activity',
        'saga-compensation',
        'signal-update',
        'version-marker',
        'wait-condition',
    ];

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     family: string,
     *     scenario: string,
     *     history: list<array{event_type: string, payload: array<string, mixed>}>,
     *     expected_state: array<string, mixed>
     * } $case
     */
    #[DataProvider('goldenHistoryCases')]
    public function testPhpGoldenHistoryReplayContract(array $case): void
    {
        Carbon::setTestNow('2026-04-21 12:00:00');
        $this->beforeApplicationDestroyed(static function (): void {
            Carbon::setTestNow();
        });

        $run = $this->createRunFromGoldenCase($case);

        $state = (new QueryStateReplayer())->query($run->fresh(['historyEvents']), 'currentState');

        $this->assertSame(
            $case['expected_state'],
            $state,
            sprintf('Golden replay case [%s] drifted from the stored history contract.', $case['id']),
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function goldenHistoryCases(): array
    {
        $cases = [];
        $coveredFamilies = [];

        foreach (self::fixturePaths() as $fixturePath) {
            $fixture = self::loadFixture($fixturePath);
            $source = $fixture['source'];

            foreach ($fixture['cases'] as $case) {
                self::assertIsArray($case);
                self::assertIsString($case['name'] ?? null);
                self::assertContains($case['family'] ?? null, self::REQUIRED_FAMILIES);
                self::assertIsString($case['scenario'] ?? null);
                self::assertIsArray($case['history'] ?? null);
                self::assertIsArray($case['expected_state'] ?? null);

                $coveredFamilies[] = $case['family'];
                $id = sprintf('%s@%s::%s', $source['runtime'], $source['version'], $case['name']);
                $cases[$id] = [[
                    ...$case,
                    'id' => $id,
                    'source' => $source,
                ]];
            }
        }

        foreach (self::REQUIRED_FAMILIES as $family) {
            self::assertContains($family, $coveredFamilies, "Missing golden replay family [{$family}].");
        }

        return $cases;
    }

    /**
     * @return list<string>
     */
    private static function fixturePaths(): array
    {
        $paths = glob(self::FIXTURE_DIR . '/*.json') ?: [];
        sort($paths);

        self::assertNotSame([], $paths, 'Expected at least one PHP golden-history fixture.');

        return array_values($paths);
    }

    /**
     * @return array{
     *     source: array{runtime: string, package: string, version: string, worker_protocol_version: string},
     *     cases: list<array<string, mixed>>
     * }
     */
    private static function loadFixture(string $fixturePath): array
    {
        $fixture = json_decode((string) file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($fixture);
        self::assertSame(self::FIXTURE_SCHEMA, $fixture['fixture_schema'] ?? null);
        self::assertIsArray($fixture['source'] ?? null);
        self::assertContains($fixture['source']['runtime'] ?? null, ['workflow-php', 'sdk-python']);
        self::assertIsString($fixture['source']['package'] ?? null);
        self::assertIsString($fixture['source']['version'] ?? null);
        self::assertStringNotContainsString('dev', strtolower($fixture['source']['version']));
        self::assertIsString($fixture['source']['worker_protocol_version'] ?? null);
        self::assertIsArray($fixture['cases'] ?? null);
        self::assertNotSame([], $fixture['cases']);

        return $fixture;
    }

    /**
     * @param array{
     *     name: string,
     *     scenario: string,
     *     history: list<array{event_type: string, payload: array<string, mixed>}>
     * } $case
     */
    private function createRunFromGoldenCase(array $case): WorkflowRun
    {
        $codec = CodecRegistry::defaultCodec();
        $instanceId = 'golden-replay-' . Str::slug($case['scenario']) . '-' . strtolower((string) Str::ulid());

        /** @var WorkflowInstance $instance */
        $instance = WorkflowInstance::query()->create([
            'id' => $instanceId,
            'workflow_class' => TestGoldenReplayWorkflow::class,
            'workflow_type' => 'test-golden-replay-workflow',
            'run_count' => 1,
        ]);

        /** @var WorkflowRun $run */
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGoldenReplayWorkflow::class,
            'workflow_type' => 'test-golden-replay-workflow',
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'payload_codec' => $codec,
            'arguments' => Serializer::serializeWithCodec($codec, [$case['scenario']]),
            'output' => null,
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => now()
                ->subMinute(),
            'closed_at' => now(),
            'last_progress_at' => now(),
        ]);

        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();

        foreach ($case['history'] as $index => $event) {
            WorkflowHistoryEvent::query()->create([
                'workflow_run_id' => $run->id,
                'sequence' => $index + 1,
                'event_type' => HistoryEventType::from($event['event_type'])->value,
                'payload' => $this->normalizeGoldenPayload($event['payload'], $codec),
                'recorded_at' => now()
                    ->subSeconds(count($case['history']) - $index),
            ]);
        }

        $run->forceFill([
            'last_history_sequence' => count($case['history']),
            'last_progress_at' => now(),
        ])->save();

        return $run;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeGoldenPayload(array $payload, string $codec): array
    {
        foreach (['result', 'value', 'arguments'] as $field) {
            $valueKey = "{$field}_value";
            if (! array_key_exists($valueKey, $payload)) {
                continue;
            }

            $payload[$field] = Serializer::serializeWithCodec($codec, $payload[$valueKey]);
            $payload['payload_codec'] ??= $codec;
            unset($payload[$valueKey]);
        }

        return $payload;
    }
}
