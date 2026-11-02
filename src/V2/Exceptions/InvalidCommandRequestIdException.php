<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use InvalidArgumentException;

final class InvalidCommandRequestIdException extends InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('The request ID must be valid UTF-8 and contain 1 to 255 Unicode characters.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function validationErrors(): array
    {
        return [
            'request_id' => [$this->getMessage()],
        ];
    }
}
