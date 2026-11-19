<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Models\WorkflowRun;
use Workflow\WorkflowMetadata;

/**
 * Admits an all() group before its first member is written (#1232).
 *
 * The executor schedules a group one member at a time, and each member runs
 * its own structural guards just before its own writes. Without admission, a
 * member refused in the middle of a group leaves every member before it
 * scheduled, and the run's failure then cancels them. Admission runs, in
 * member order, the same pre-write checks for every member the task would
 * newly schedule, counting the members admitted before it, so a refused group
 * is refused with the exception the refused member would have thrown and
 * writes nothing but the run's failure. The per-member guards stay as the
 * backstop.
 *
 * Admission covers activities and children. It never refuses a group the
 * per-member guards admit: it checks only members the dispatch would newly
 * schedule, and stops at a reserved child, whose own dispatch refuses it.
 * Timers are not admitted. A pending-timer refusal escapes the workflow task,
 * whose transaction rolls back every member already written, and leaves the
 * task failed and recoverable, as a sequential timer's does.
 */
final class ParallelGroupAdmission
{
    private ?int $pendingActivities = null;

    private ?int $pendingChildren = null;

    public function __construct(
        private readonly WorkflowRun $run,
    ) {
    }

    /**
     * The checks scheduling an activity runs before its first write.
     */
    public function admitActivity(ActivityCall $call): void
    {
        $limit = StructuralLimits::pendingActivityLimit();
        if ($limit > 0) {
            $this->pendingActivities ??= StructuralLimits::pendingActivityCount($this->run);
            StructuralLimits::guardPendingActivityCount($this->pendingActivities);
            ++$this->pendingActivities;
        }

        EntryMethod::describeActivity($call->activity);
        ActivityOptions::assertMaxAttempts($call->options?->maxAttempts);

        [, , $serialized] = self::activityArguments($this->run, $call);
        $this->admitPayload($serialized);
    }

    /**
     * The checks scheduling a child without a reservation runs before its
     * first write.
     */
    public function admitChild(ChildWorkflowCall $call): void
    {
        $limit = StructuralLimits::pendingChildLimit();
        if ($limit > 0) {
            $this->pendingChildren ??= StructuralLimits::pendingChildCount($this->run);
            StructuralLimits::guardPendingChildCount($this->pendingChildren);
            ++$this->pendingChildren;
        }

        $metadata = WorkflowMetadata::fromStartArguments($call->arguments);
        TypeRegistry::for($call->workflow);
        RunCommandContract::snapshot($call->workflow);

        [, $serialized] = self::childArguments($this->run, $metadata);
        $this->admitPayload($serialized);
    }

    /**
     * An activity's arguments as scheduling stores them, before any external
     * storage.
     *
     * @return array{0: list<int>, 1: string, 2: string} reference positions,
     *         codec and serialized arguments
     */
    public static function activityArguments(WorkflowRun $run, ActivityCall $call): array
    {
        [$arguments, $referencePositions] = EntityReferenceArguments::encode($call->arguments);
        $codec = Serializer::chooseCodecForData($run->payload_codec, $arguments);

        return [$referencePositions, $codec, Serializer::serializeWithCodec($codec, $arguments)];
    }

    /**
     * A child's start arguments as scheduling stores them, before any
     * external storage. The child inherits the parent's codec, or the package
     * default for a parent without one.
     *
     * @return array{0: string, 1: string} codec and serialized arguments
     */
    public static function childArguments(WorkflowRun $run, WorkflowMetadata $metadata): array
    {
        $preferredCodec = is_string($run->payload_codec) && $run->payload_codec !== ''
            ? $run->payload_codec
            : CodecRegistry::defaultCodec();
        $codec = Serializer::chooseCodecForData($preferredCodec, $metadata->arguments);

        return [$codec, Serializer::serializeWithCodec($codec, $metadata->arguments)];
    }

    /**
     * The stored payload size check. A payload external storage will take is
     * stored as a small reference, which the per-member guard still checks.
     */
    private function admitPayload(string $serialized): void
    {
        $namespace = is_string($this->run->namespace) ? $this->run->namespace : null;
        if (ExternalPayloads::wouldExternalizeForNamespace($serialized, $namespace)) {
            return;
        }

        StructuralLimits::guardPayloadSize($serialized);
    }
}
