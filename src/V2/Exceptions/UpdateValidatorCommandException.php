<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

/**
 * Thrown when an update validator tries to schedule durable work.
 *
 * Validators run against replayed state before an update is accepted and must
 * not record commands. The exception surfaces inside the validator, so the
 * update is rejected and nothing is scheduled.
 */
final class UpdateValidatorCommandException extends LogicException
{
    public function __construct(
        private readonly string $updateName,
        private readonly string $commandType,
    ) {
        parent::__construct(sprintf(
            'Update validator for [%s] cannot schedule durable work [%s]; update validators are read-only.',
            $updateName,
            $commandType,
        ));
    }

    public function updateName(): string
    {
        return $this->updateName;
    }

    public function commandType(): string
    {
        return $this->commandType;
    }
}
