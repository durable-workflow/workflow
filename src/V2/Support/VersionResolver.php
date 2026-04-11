<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\Exceptions\VersionNotSupportedException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowLink;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

final class VersionResolver
{
    public static function resolve(
        WorkflowRun $run,
        ?WorkflowHistoryEvent $event,
        VersionCall $versionCall,
        int $sequence,
    ): VersionResolution {
        if ($event !== null) {
            return VersionResolution::recorded(self::recordedVersion($event, $versionCall, $sequence));
        }

        if (self::shouldUseLegacyDefault($run, $sequence)) {
            $version = WorkflowStub::DEFAULT_VERSION;
            self::assertSupported($version, $versionCall);

            return VersionResolution::legacyDefault($version);
        }

        return VersionResolution::fresh($versionCall->maxSupported);
    }

    private static function recordedVersion(
        WorkflowHistoryEvent $event,
        VersionCall $versionCall,
        int $sequence,
    ): int {
        $recordedChangeId = self::stringValue($event->payload['change_id'] ?? null);

        if ($recordedChangeId === null) {
            throw new LogicException(sprintf(
                'Workflow version marker at workflow sequence [%d] is missing a change ID.',
                $sequence,
            ));
        }

        if ($recordedChangeId !== $versionCall->changeId) {
            throw new LogicException(sprintf(
                'Workflow version marker at workflow sequence [%d] expected change ID [%s] but history recorded [%s].',
                $sequence,
                $versionCall->changeId,
                $recordedChangeId,
            ));
        }

        $version = $event->payload['version'] ?? null;

        if (! is_int($version)) {
            throw new LogicException(sprintf(
                'Workflow version marker [%s] at workflow sequence [%d] is missing an integer version.',
                $versionCall->changeId,
                $sequence,
            ));
        }

        self::assertSupported($version, $versionCall);

        return $version;
    }

    private static function shouldUseLegacyDefault(WorkflowRun $run, int $sequence): bool
    {
        if (self::runCompatibilityDiffersFromCurrent($run)) {
            return true;
        }

        if (self::runDefinitionFingerprintDiffersFromCurrent($run)) {
            return true;
        }

        return self::sequenceAlreadyOccupied($run, $sequence);
    }

    private static function runCompatibilityDiffersFromCurrent(WorkflowRun $run): bool
    {
        $runCompatibility = self::stringValue($run->compatibility ?? null);
        $currentCompatibility = WorkerCompatibility::current();

        return $runCompatibility !== null
            && $currentCompatibility !== null
            && $runCompatibility !== $currentCompatibility;
    }

    private static function sequenceAlreadyOccupied(WorkflowRun $run, int $sequence): bool
    {
        $historyEvents = $run->relationLoaded('historyEvents')
            ? $run->historyEvents
            : collect();

        if ($historyEvents->contains(
            static fn (WorkflowHistoryEvent $event): bool => $event->event_type !== HistoryEventType::VersionMarkerRecorded
                && ($event->payload['sequence'] ?? null) === $sequence
        )) {
            return true;
        }

        $activityExecutions = $run->relationLoaded('activityExecutions')
            ? $run->activityExecutions
            : collect();

        if ($activityExecutions->contains(
            static fn (object $execution): bool => ($execution->sequence ?? null) === $sequence
        )) {
            return true;
        }

        $timers = $run->relationLoaded('timers')
            ? $run->timers
            : collect();

        if ($timers->contains(static fn (object $timer): bool => ($timer->sequence ?? null) === $sequence)) {
            return true;
        }

        $childLinks = $run->relationLoaded('childLinks')
            ? $run->childLinks
            : collect();

        return $childLinks->contains(
            static fn (WorkflowLink $link): bool => $link->link_type === 'child_workflow'
                && $link->sequence === $sequence
        );
    }

    private static function runDefinitionFingerprintDiffersFromCurrent(WorkflowRun $run): bool
    {
        $recordedFingerprint = WorkflowDefinitionFingerprint::recordedForRun($run);
        $currentFingerprint = WorkflowDefinitionFingerprint::currentForRun($run);

        return $recordedFingerprint !== null
            && $currentFingerprint !== null
            && ! hash_equals($recordedFingerprint, $currentFingerprint);
    }

    private static function assertSupported(int $version, VersionCall $versionCall): void
    {
        if ($version < $versionCall->minSupported || $version > $versionCall->maxSupported) {
            throw new VersionNotSupportedException(sprintf(
                "Version %d for change ID '%s' is not supported. Supported range: [%d, %d]",
                $version,
                $versionCall->changeId,
                $versionCall->minSupported,
                $versionCall->maxSupported,
            ));
        }
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
