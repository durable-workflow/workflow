<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Support\Facades\Log;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Exceptions\WorkflowPayloadDecodeException;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowUpdate;

final class WorkflowPayloadDecoder
{
    /**
     * @param array<string, mixed> $context
     */
    public static function unserializeWithRun(string $serialized, ?WorkflowRun $run, array $context): mixed
    {
        $codec = self::stringValue($run?->payload_codec);

        try {
            return $codec !== null
                ? Serializer::unserializeWithCodec($codec, $serialized)
                : Serializer::unserialize($serialized);
        } catch (Throwable $throwable) {
            throw self::failure($throwable, $context, $codec, $serialized);
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, mixed>
     */
    public static function commandArguments(WorkflowCommand $command, array $context): array
    {
        try {
            return $command->payloadArguments();
        } catch (Throwable $throwable) {
            throw self::failure(
                $throwable,
                $context + [
                    'workflow_command_id' => $command->id,
                    'workflow_id' => $command->workflow_instance_id,
                    'run_id' => $command->workflow_run_id,
                ],
                self::stringValue($command->payload_codec),
                self::stringValue($command->payload),
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function commandTargetName(WorkflowCommand $command, array $context): ?string
    {
        try {
            return $command->targetName();
        } catch (Throwable $throwable) {
            throw self::failure(
                $throwable,
                $context + [
                    'workflow_command_id' => $command->id,
                    'workflow_id' => $command->workflow_instance_id,
                    'run_id' => $command->workflow_run_id,
                ],
                self::stringValue($command->payload_codec),
                self::stringValue($command->payload),
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, mixed>
     */
    public static function updateArguments(WorkflowUpdate $update, array $context): array
    {
        try {
            return $update->updateArguments();
        } catch (Throwable $throwable) {
            throw self::failure(
                $throwable,
                $context + [
                    'update_id' => $update->id,
                    'workflow_command_id' => $update->workflow_command_id,
                    'workflow_id' => $update->workflow_instance_id,
                    'run_id' => $update->workflow_run_id,
                    'update_name' => $update->update_name,
                ],
                self::stringValue($update->payload_codec),
                self::stringValue($update->arguments),
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function failure(
        Throwable $throwable,
        array $context,
        ?string $codec,
        ?string $payload,
    ): WorkflowPayloadDecodeException {
        $diagnostics = array_filter($context + [
            'codec' => $codec,
            'exception_type' => $throwable::class,
            'payload_head' => $payload === null ? null : substr($payload, 0, 96),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        Log::warning('Workflow payload decode failed.', $diagnostics);

        return new WorkflowPayloadDecodeException($diagnostics, $throwable);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
