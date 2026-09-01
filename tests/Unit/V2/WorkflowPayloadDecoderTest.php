<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Support\Facades\Log;
use Orchestra\Testbench\TestCase;
use UnexpectedValueException;
use Workflow\V2\Exceptions\WorkflowPayloadDecodeException;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowUpdate;
use Workflow\V2\Support\WorkflowPayloadDecoder;

final class WorkflowPayloadDecoderTest extends TestCase
{
    public function testCommandArgumentFailuresIncludeCommandIdentityAndBoundedPayloadEvidence(): void
    {
        $payload = str_repeat('x', 120);
        $command = new class() extends WorkflowCommand {
            public function payloadArguments(): array
            {
                throw new UnexpectedValueException('invalid command arguments');
            }
        };
        $command->forceFill([
            'id' => 'command-1',
            'workflow_instance_id' => 'workflow-1',
            'workflow_run_id' => 'run-1',
            'payload_codec' => 'avro',
            'payload' => $payload,
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool =>
                $message === 'Workflow payload decode failed.'
                && $context === [
                    'receiver_name' => 'signal',
                    'workflow_command_id' => 'command-1',
                    'workflow_id' => 'workflow-1',
                    'run_id' => 'run-1',
                    'codec' => 'avro',
                    'exception_type' => UnexpectedValueException::class,
                    'payload_head' => str_repeat('x', 96),
                ]);

        try {
            WorkflowPayloadDecoder::commandArguments($command, [
                'receiver_name' => 'signal',
            ]);
            self::fail('Expected the command payload failure to be wrapped.');
        } catch (WorkflowPayloadDecodeException $exception) {
            self::assertSame('command-1', $exception->context['workflow_command_id']);
            self::assertSame($payload, $command->payload);
            self::assertInstanceOf(UnexpectedValueException::class, $exception->getPrevious());
        }
    }

    public function testCommandTargetFailuresRetainTheRequestedReceiverContext(): void
    {
        $command = new class() extends WorkflowCommand {
            public function targetName(): ?string
            {
                throw new UnexpectedValueException('invalid target name');
            }
        };
        $command->forceFill([
            'id' => 'command-2',
            'workflow_instance_id' => 'workflow-2',
            'workflow_run_id' => 'run-2',
            'payload_codec' => 'avro',
            'payload' => 'broken-target',
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool =>
                $message === 'Workflow payload decode failed.'
                && ($context['receiver_name'] ?? null) === 'signal target'
                && ($context['workflow_command_id'] ?? null) === 'command-2'
                && ($context['payload_head'] ?? null) === 'broken-target');

        try {
            WorkflowPayloadDecoder::commandTargetName($command, [
                'receiver_name' => 'signal target',
            ]);
            self::fail('Expected the target-name failure to be wrapped.');
        } catch (WorkflowPayloadDecodeException $exception) {
            self::assertStringContainsString('signal target', $exception->getMessage());
            self::assertInstanceOf(UnexpectedValueException::class, $exception->getPrevious());
        }
    }

    public function testUpdateArgumentFailuresIncludeUpdateAndWorkflowIdentity(): void
    {
        $update = new class() extends WorkflowUpdate {
            public function updateArguments(): array
            {
                throw new UnexpectedValueException('invalid update arguments');
            }
        };
        $update->forceFill([
            'id' => 'update-1',
            'workflow_command_id' => 'command-3',
            'workflow_instance_id' => 'workflow-3',
            'workflow_run_id' => 'run-3',
            'update_name' => 'approve',
            'payload_codec' => 'avro',
            'arguments' => 'broken-update',
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool =>
                $message === 'Workflow payload decode failed.'
                && ($context['update_id'] ?? null) === 'update-1'
                && ($context['workflow_command_id'] ?? null) === 'command-3'
                && ($context['workflow_id'] ?? null) === 'workflow-3'
                && ($context['run_id'] ?? null) === 'run-3'
                && ($context['update_name'] ?? null) === 'approve'
                && ($context['codec'] ?? null) === 'avro'
                && ($context['payload_head'] ?? null) === 'broken-update');

        try {
            WorkflowPayloadDecoder::updateArguments($update, [
                'receiver_name' => 'update',
            ]);
            self::fail('Expected the update payload failure to be wrapped.');
        } catch (WorkflowPayloadDecodeException $exception) {
            self::assertSame('approve', $exception->context['update_name']);
            self::assertInstanceOf(UnexpectedValueException::class, $exception->getPrevious());
        }
    }

    protected function getPackageProviders($app): array
    {
        return [\Workflow\Providers\WorkflowServiceProvider::class];
    }
}
