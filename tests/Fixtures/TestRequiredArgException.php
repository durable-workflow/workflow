<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Exception;

class TestRequiredArgException extends Exception
{
    public function __construct(
        string $message,
        int $code,
        private readonly string $requiredContext,
    ) {
        parent::__construct($message, $code);
    }
}
