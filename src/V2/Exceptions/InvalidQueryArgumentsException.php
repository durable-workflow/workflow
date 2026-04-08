<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use LogicException;

final class InvalidQueryArgumentsException extends LogicException
{
    /**
     * @param array<string, list<string>> $validationErrors
     */
    public function __construct(
        private readonly string $queryName,
        private readonly array $validationErrors,
    ) {
        parent::__construct(sprintf('Workflow query [%s] received invalid arguments.', $queryName));
    }

    public function queryName(): string
    {
        return $this->queryName;
    }

    /**
     * @return array<string, list<string>>
     */
    public function validationErrors(): array
    {
        return $this->validationErrors;
    }
}
