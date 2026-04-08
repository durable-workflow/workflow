<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Contracts\YieldedCommand;

final class VersionCall implements YieldedCommand
{
    public function __construct(
        public readonly string $changeId,
        public readonly int $minSupported,
        public readonly int $maxSupported,
    ) {
        if ($this->changeId === '') {
            throw new LogicException('V2 version change ids must be non-empty strings.');
        }

        if ($this->minSupported > $this->maxSupported) {
            throw new LogicException(sprintf(
                'V2 version change [%s] has minSupported [%d] greater than maxSupported [%d].',
                $this->changeId,
                $this->minSupported,
                $this->maxSupported,
            ));
        }
    }
}
