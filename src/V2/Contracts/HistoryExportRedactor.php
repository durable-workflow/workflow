<?php

declare(strict_types=1);

namespace Workflow\V2\Contracts;

interface HistoryExportRedactor
{
    /**
     * @param array<string, mixed> $context
     */
    public function redact(mixed $value, array $context): mixed;
}
