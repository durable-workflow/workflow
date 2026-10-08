<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use LogicException;
use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Signal('value')]
#[Signal('finish')]
final class TestControlPlaneInspectionWorkflow extends Workflow
{
    private mixed $value = null;

    private bool $received = false;

    public function handle(): mixed
    {
        $this->value = signal('value');
        $this->received = true;
        signal('finish');

        return $this->value;
    }

    #[QueryMethod('inspection-value')]
    public function currentValue(): mixed
    {
        return $this->value;
    }

    #[QueryMethod('contains-text')]
    public function containsText(string $needle): bool
    {
        return is_string($this->value) && str_contains($this->value, $needle);
    }

    #[QueryMethod('required-value')]
    public function requireValue(): mixed
    {
        if (! $this->received) {
            throw new LogicException('A value has not been received.');
        }

        return $this->value;
    }
}
