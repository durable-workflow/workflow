<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Exceptions\VersionRangeMismatchException;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\WorkflowStub;

final class VersionResolver
{
    public static function reuse(
        int $version,
        VersionCall $versionCall,
        ?int $sequence = null,
    ): VersionResolution {
        self::assertSupported($version, $versionCall, $sequence);

        return VersionResolution::reused($version);
    }

    public static function resolve(
        WorkflowRun $run,
        ?WorkflowHistoryEvent $event,
        VersionCall $versionCall,
        int $sequence,
    ): VersionResolution {
        if ($event !== null) {
            return VersionResolution::recorded(self::recordedVersion($event, $versionCall, $sequence));
        }

        if (self::shouldUseLegacyDefault($run)) {
            $version = WorkflowStub::DEFAULT_VERSION;
            self::assertSupported($version, $versionCall, $sequence);

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

        self::assertSupported($version, $versionCall, $sequence);

        return $version;
    }

    private static function shouldUseLegacyDefault(WorkflowRun $run): bool
    {
        if (self::runCompatibilityDiffersFromCurrent($run)) {
            return true;
        }

        $fingerprintMatch = WorkflowDefinitionFingerprint::matchesCurrent($run);

        if ($fingerprintMatch === false) {
            return true;
        }

        if ($fingerprintMatch === true) {
            return false;
        }

        // Fingerprint unavailable: the run predates fingerprinting or the
        // workflow class cannot be resolved.  Without durable evidence that
        // the definition matches, conservatively treat missing version
        // markers as belonging to an older definition.
        return true;
    }

    private static function runCompatibilityDiffersFromCurrent(WorkflowRun $run): bool
    {
        $runCompatibility = self::stringValue($run->compatibility ?? null);
        $currentCompatibility = WorkerCompatibility::current();

        return $runCompatibility !== null
            && $currentCompatibility !== null
            && $runCompatibility !== $currentCompatibility;
    }

    private static function assertSupported(int $version, VersionCall $versionCall, ?int $sequence): void
    {
        if ($version < $versionCall->minSupported || $version > $versionCall->maxSupported) {
            throw new VersionRangeMismatchException(
                $versionCall->changeId,
                $version,
                $versionCall->minSupported,
                $versionCall->maxSupported,
                $sequence,
            );
        }
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
