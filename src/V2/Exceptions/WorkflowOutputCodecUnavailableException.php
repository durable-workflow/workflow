<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use RuntimeException;

final class WorkflowOutputCodecUnavailableException extends RuntimeException
{
    public function __construct(?string $runId = null)
    {
        parent::__construct(sprintf(
            'Workflow output codec is unavailable%s; the terminal output cannot be decoded safely.',
            $runId !== null && $runId !== '' ? sprintf(' for run [%s]', $runId) : '',
        ));
    }
}
