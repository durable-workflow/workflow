<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Exceptions\InvalidExternalSignalException;
use function Workflow\V2\signalExternalWorkflow;
use Workflow\V2\Workflow;

#[Type('test-signal-external-invalid-name-workflow')]
final class TestSignalExternalInvalidNameWorkflow extends Workflow
{
    public function handle(string $target, string $name): array
    {
        try {
            signalExternalWorkflow($target, $name, ['never-sent']);
        } catch (InvalidExternalSignalException $exception) {
            return [
                'reason' => $exception->reason,
                'field' => $exception->field,
            ];
        }

        return [
            'reason' => null,
        ];
    }
}
