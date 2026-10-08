<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Tests\Fixtures\V2\TestCommandTargetWorkflow;
use Tests\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Contracts\HistoryExportRedactor;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowRunSummary;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\BundleIntegrityVerifier;
use Workflow\V2\Support\HistoryExport;

final class HistoryExportRedactionPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function callablePolicies(): iterable
    {
        yield 'interface class' => ['interface-class', ExportInterfacePolicy::class];
        yield 'interface object' => ['interface-object', ExportInterfacePolicy::class];
        yield 'configured closure' => ['closure', 'closure'];
        yield 'explicit closure overrides invalid configuration' => ['override', 'closure'];
        yield 'object method' => ['object-method', ExportCallablePolicy::class . '::redact'];
        yield 'static method array' => ['static-method', ExportCallablePolicy::class . '::redactStatic'];
        yield 'static method string' => ['static-string', ExportCallablePolicy::class . '::redactStatic'];
        yield 'invokable object' => ['invokable', ExportCallablePolicy::class];
        yield 'invokable class' => ['invokable-class', ExportCallablePolicy::class];
        yield 'named function' => ['function', __NAMESPACE__ . '\\exportPolicyCallback'];
    }

    #[DataProvider('callablePolicies')]
    public function testSupportedPoliciesHaveStableNamesAndPreserveStoredPayloads(string $kind, string $name): void
    {
        $run = $this->completedRun();
        $before = $this->storedRecords($run);
        $policy = match ($kind) {
            'interface-class' => ExportInterfacePolicy::class,
            'interface-object' => new ExportInterfacePolicy(),
            'closure' => static fn (mixed $value, array $context): string => 'masked:' . $context['path'],
            'object-method' => [new ExportCallablePolicy(), 'redact'],
            'static-method' => [ExportCallablePolicy::class, 'redactStatic'],
            'static-string' => ExportCallablePolicy::class . '::redactStatic',
            'invokable' => new ExportCallablePolicy(),
            'invokable-class' => ExportCallablePolicy::class,
            'function' => __NAMESPACE__ . '\\exportPolicyCallback',
            'override' => new stdClass(),
        };
        config()
            ->set('workflows.v2.history_export.redactor', $policy);
        $explicit = $kind === 'override'
            ? static fn (mixed $value, array $context): string => 'masked:' . $context['path']
            : null;
        $first = null;

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $bundle = HistoryExport::forRun($run->fresh(), $this->exportedAt(), $explicit);
            $this->assertSame([
                'applied' => true,
                'policy' => $name,
                'paths' => [
                    'workflow.memo',
                    'workflow.memo_payload',
                    'payloads.arguments.data',
                    'payloads.output.data',
                ],
            ], $bundle['redaction']);
            $this->assertSame('masked:workflow.memo', $bundle['workflow']['memo']);
            $this->assertSame('masked:workflow.memo_payload', $bundle['workflow']['memo_payload']);
            $this->assertSame('masked:payloads.arguments.data', $bundle['payloads']['arguments']['data']);
            $this->assertSame('masked:payloads.output.data', $bundle['payloads']['output']['data']);
            $this->assertTrue(BundleIntegrityVerifier::verify($bundle)['integrity']['checksum_matches']);
            $this->assertSame($before, $this->storedRecords($run));
            if ($first !== null) {
                $this->assertSame($first, $this->wireValue($bundle));
            }
            $first = $this->wireValue($bundle);
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function disabledPolicies(): iterable
    {
        yield 'unset' => [null];
        yield 'false' => [false];
        yield 'empty string' => [''];
    }

    #[DataProvider('disabledPolicies')]
    public function testDisabledPolicyKeepsAnUnredactedVerifiableExport(mixed $policy): void
    {
        $run = $this->completedRun();
        $baseline = HistoryExport::forRun($run->fresh(), $this->exportedAt());
        $before = $this->storedRecords($run);
        config()
            ->set('workflows.v2.history_export.redactor', $policy);

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $bundle = HistoryExport::forRun($run->fresh(), $this->exportedAt());
            $this->assertSame([
                'applied' => false,
                'policy' => null,
                'paths' => [],
            ], $bundle['redaction']);
            $this->assertSame($run->arguments, $bundle['payloads']['arguments']['data']);
            $this->assertSame($run->output, $bundle['payloads']['output']['data']);
            $this->assertSame($baseline, $bundle);
            $this->assertTrue(BundleIntegrityVerifier::verify($bundle)['integrity']['checksum_matches']);
            $this->assertSame($before, $this->storedRecords($run));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPolicies(): iterable
    {
        yield 'integer' => ['integer'];
        yield 'boolean true' => ['boolean'];
        yield 'object without callable contract' => ['object'];
        yield 'resolved class without callable contract' => ['class'];
    }

    #[DataProvider('invalidPolicies')]
    public function testInvalidConfigurationReportsItsContractWithoutMutatingStoredRecords(string $kind): void
    {
        $run = $this->completedRun();
        $before = $this->storedRecords($run);
        config()
            ->set('workflows.v2.history_export.redactor', match ($kind) {
                'integer' => 17,
                'boolean' => true,
                'object' => new stdClass(),
                'class' => stdClass::class,
            });

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                HistoryExport::forRun($run->fresh(), $this->exportedAt());
                $this->fail('Invalid configured policy must fail before returning an export.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Configured workflow v2 history export redactor must implement '
                    . HistoryExportRedactor::class . ' or be callable.',
                    $exception->getMessage(),
                );
            }
            $this->assertSame($before, $this->storedRecords($run));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function throwingPaths(): iterable
    {
        yield 'memo projection' => ['workflow.memo'];
        yield 'workflow arguments' => ['payloads.arguments.data'];
        yield 'update arguments' => ['updates.0.arguments'];
        yield 'typed failure message' => ['timeline.0.failure.message'];
    }

    #[DataProvider('throwingPaths')]
    public function testCallbackFailureNamesItsPathAndPreservesTheOriginalException(string $path): void
    {
        [$run] = $this->failedRun();
        $before = $this->storedRecords($run);
        $original = new RuntimeException('policy denied export');
        $callback = static function (mixed $value, array $context) use ($path, $original): mixed {
            if ($context['path'] === $path) {
                throw $original;
            }

            return $value;
        };

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                HistoryExport::forRun($run->fresh(), $this->exportedAt(), $callback);
                $this->fail('The throwing callback must prevent returning a partial export.');
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Workflow v2 history export redactor failed for [' . $path . ']: policy denied export',
                    $exception->getMessage()
                );
                $this->assertSame($original, $exception->getPrevious());
            }
            $this->assertSame($before, $this->storedRecords($run));
        }
    }

    public function testTypedFailureAndUpdateFieldsReceivePreciseContextAndRedactedManifests(): void
    {
        [$run, $update, $event, $failure] = $this->failedRun();
        $before = $this->storedRecords($run);
        $contexts = [];
        $callback = static function (mixed $value, array $context) use (&$contexts): string {
            $contexts[$context['path']] = $context;

            return 'masked:' . $context['path'];
        };
        $identity = [
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'workflow_type' => 'export.policy',
        ];
        $first = null;

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $bundle = HistoryExport::forRun($run->fresh(), $this->exportedAt(), $callback);
            $this->assertCount(1, $bundle['timeline']);
            $this->assertCount(1, $bundle['updates']);
            foreach (['message', 'file'] as $field) {
                $path = 'timeline.0.failure.' . $field;
                $this->assertSame('masked:' . $path, $bundle['timeline'][0]['failure'][$field]);
                $this->assertContext($identity + [
                    'path' => $path,
                    'category' => 'failure_diagnostic',
                    'history_event_id' => $event->id,
                    'history_event_type' => HistoryEventType::WorkflowFailed->value,
                    'failure_id' => $failure->id,
                    'source_kind' => 'workflow_run',
                    'source_id' => $run->id,
                    'field' => $field,
                ], $contexts[$path]);
            }
            foreach (['arguments', 'result'] as $field) {
                $path = 'updates.0.' . $field;
                $this->assertSame('masked:' . $path, $bundle['updates'][0][$field]);
                $this->assertContext($identity + [
                    'path' => $path,
                    'category' => 'update_payload',
                    'update_id' => $update->id,
                    'update_name' => 'approve',
                    'field' => $field,
                ], $contexts[$path]);
                $entry = collect($bundle['payload_manifest']['entries'])->firstWhere('path', $path);
                $this->assertIsArray($entry);
                $this->assertTrue($entry['redacted']);
                $this->assertSame('payload_redacted', $entry['diagnostic']);
                $this->assertNull($entry['writer_schema']);
            }
            $this->assertSame(array_keys($contexts), $bundle['redaction']['paths']);
            $this->assertTrue(BundleIntegrityVerifier::verify($bundle)['integrity']['checksum_matches']);
            $this->assertSame($before, $this->storedRecords($run));
            if ($first !== null) {
                $this->assertSame($first, $this->wireValue($bundle));
            }
            $first = $this->wireValue($bundle);
        }
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     */
    private function assertContext(array $expected, array $actual): void
    {
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    /**
     * @return array{WorkflowRun, WorkflowUpdate, WorkflowHistoryEvent, WorkflowFailure}
     */
    private function failedRun(): array
    {
        $run = $this->completedRun();
        $run->forceFill([
            'status' => RunStatus::Failed->value,
            'closed_reason' => 'failed',
            'output' => null,
        ])->save();
        $instance = $run->instance;
        $this->assertInstanceOf(WorkflowInstance::class, $instance);
        $command = WorkflowCommand::record($instance, $run, [
            'command_type' => CommandType::Update->value,
            'target_scope' => 'instance',
            'payload_codec' => config('workflows.serializer'),
            'payload' => Serializer::serialize([
                'name' => 'approve',
                'arguments' => ['secret-argument'],
            ]),
            'source' => 'php',
            'status' => CommandStatus::Accepted->value,
            'outcome' => CommandOutcome::UpdateCompleted->value,
            'accepted_at' => $this->exportedAt()
                ->subMinute(),
            'applied_at' => $this->exportedAt()
                ->subMinute(),
        ]);
        $update = WorkflowUpdate::query()->create([
            'workflow_command_id' => $command->id,
            'workflow_instance_id' => $run->workflow_instance_id,
            'workflow_run_id' => $run->id,
            'command_sequence' => $command->command_sequence,
            'workflow_sequence' => 1,
            'update_name' => 'approve',
            'status' => 'completed',
            'outcome' => 'update_completed',
            'payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['secret-argument']),
            'result' => Serializer::serialize([
                'approved' => true,
            ]),
            'accepted_at' => $this->exportedAt()
                ->subMinute(),
            'applied_at' => $this->exportedAt()
                ->subMinute(),
            'closed_at' => $this->exportedAt()
                ->subMinute(),
        ]);
        $failure = WorkflowFailure::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_run_id' => $run->id,
            'source_kind' => 'workflow_run',
            'source_id' => $run->id,
            'propagation_kind' => 'terminal',
            'handled' => false,
            'exception_class' => RuntimeException::class,
            'message' => 'secret failure',
            'file' => '/app/workflow.php',
            'line' => 17,
            'trace_preview' => 'secret trace',
        ]);
        $event = WorkflowHistoryEvent::record($run, HistoryEventType::WorkflowFailed, [
            'failure_id' => $failure->id,
            'source_kind' => 'workflow_run',
            'source_id' => $run->id,
            'exception_type' => 'runtime.failure',
            'exception_class' => RuntimeException::class,
            'message' => 'secret failure',
            'exception' => [
                'type' => 'runtime.failure',
                'class' => RuntimeException::class,
                'message' => 'secret failure',
                'code' => 23,
                'file' => '/app/workflow.php',
                'line' => 17,
                'trace' => [],
                'properties' => [],
            ],
        ]);
        HistoryExport::forRun($run->fresh(), $this->exportedAt());

        return [$run->fresh(), $update, $event, $failure];
    }

    private function completedRun(): WorkflowRun
    {
        config()->set('workflows.v2.history_export.redactor', null);
        $instance = WorkflowInstance::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'export.policy',
            'run_count' => 1,
        ]);
        $run = WorkflowRun::query()->create([
            'id' => (string) Str::ulid(),
            'workflow_instance_id' => $instance->id,
            'workflow_class' => TestCommandTargetWorkflow::class,
            'workflow_type' => 'export.policy',
            'run_number' => 1,
            'status' => RunStatus::Completed->value,
            'closed_reason' => 'completed',
            'payload_codec' => config('workflows.serializer'),
            'output_payload_codec' => config('workflows.serializer'),
            'arguments' => Serializer::serialize(['secret-order']),
            'output' => Serializer::serialize([
                'approved' => true,
            ]),
            'connection' => 'redis',
            'queue' => 'workflow',
            'started_at' => $this->exportedAt()
                ->subMinutes(2),
            'closed_at' => $this->exportedAt()
                ->subMinute(),
            'last_progress_at' => $this->exportedAt()
                ->subMinute(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        HistoryExport::forRun($run->fresh(), $this->exportedAt());

        return $run;
    }

    /**
     * Compare the exported wire value, including timestamps from freshly hydrated waits.
     *
     * @param array<string, mixed> $bundle
     * @return array<string, mixed>
     */
    private function wireValue(array $bundle): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            json_encode($bundle, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return $decoded;
    }

    private function exportedAt(): Carbon
    {
        return Carbon::parse('2026-10-08T12:00:00Z');
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function storedRecords(WorkflowRun $run): array
    {
        $queries = [
            'instance' => WorkflowInstance::query()->whereKey($run->workflow_instance_id),
            'run' => WorkflowRun::query()->whereKey($run->id),
            'summary' => WorkflowRunSummary::query()->whereKey($run->id),
            'commands' => WorkflowCommand::query()->where('workflow_run_id', $run->id),
            'history' => WorkflowHistoryEvent::query()->where('workflow_run_id', $run->id),
            'failures' => WorkflowFailure::query()->where('workflow_run_id', $run->id),
            'updates' => WorkflowUpdate::query()->where('workflow_run_id', $run->id),
        ];
        $records = [];
        foreach ($queries as $name => $query) {
            $records[$name] = $query->orderBy('id')->get()
                ->map(static fn (Model $record): array => $record->getAttributes())
                ->values()
                ->all();
        }

        return $records;
    }
}

final class ExportInterfacePolicy implements HistoryExportRedactor
{
    /**
     * @param array<string, mixed> $context
     */
    public function redact(mixed $value, array $context): string
    {
        return 'masked:' . $context['path'];
    }
}

final class ExportCallablePolicy
{
    /**
     * @param array<string, mixed> $context
     */
    public function __invoke(mixed $value, array $context): string
    {
        return self::redactStatic($value, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function redact(mixed $value, array $context): string
    {
        return self::redactStatic($value, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function redactStatic(mixed $value, array $context): string
    {
        return 'masked:' . $context['path'];
    }
}

/** @param array<string, mixed> $context */
function exportPolicyCallback(mixed $value, array $context): string
{
    return 'masked:' . $context['path'];
}
