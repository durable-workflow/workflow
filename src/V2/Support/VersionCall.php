<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Contracts\YieldedCommand;
use Workflow\V2\WorkflowStub;

final class VersionCall implements YieldedCommand
{
    public const RESULT_VERSION = 'version';

    public const RESULT_PATCHED = 'patched';

    public const RESULT_DEPRECATE_PATCH = 'deprecate_patch';

    public function __construct(
        public readonly string $changeId,
        public readonly int $minSupported,
        public readonly int $maxSupported,
        public readonly string $resultKind = self::RESULT_VERSION,
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

        if (! in_array($this->resultKind, [
            self::RESULT_VERSION,
            self::RESULT_PATCHED,
            self::RESULT_DEPRECATE_PATCH,
        ], true)) {
            throw new LogicException(sprintf(
                'V2 version change [%s] has unsupported result kind [%s].',
                $this->changeId,
                $this->resultKind,
            ));
        }
    }

    public static function patched(string $changeId): self
    {
        return new self($changeId, WorkflowStub::DEFAULT_VERSION, 1, self::RESULT_PATCHED);
    }

    public static function deprecatePatch(string $changeId): self
    {
        return new self($changeId, WorkflowStub::DEFAULT_VERSION, 1, self::RESULT_DEPRECATE_PATCH);
    }

    public function resolveValue(int $version): mixed
    {
        return match ($this->resultKind) {
            self::RESULT_PATCHED => $version === 1,
            self::RESULT_DEPRECATE_PATCH => null,
            default => $version,
        };
    }
}
