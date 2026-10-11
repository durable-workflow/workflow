<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use Workflow\Exceptions\VersionNotSupportedException;

final class VersionRangeMismatchException extends VersionNotSupportedException
{
    public function __construct(
        public readonly string $changeId,
        public readonly int $recordedVersion,
        public readonly int $minSupported,
        public readonly int $maxSupported,
        public readonly ?int $workflowSequence = null,
    ) {
        parent::__construct(sprintf(
            "Version %d for change ID '%s' is not supported. Supported range: [%d, %d]",
            $recordedVersion,
            $changeId,
            $minSupported,
            $maxSupported,
        ));
    }
}
