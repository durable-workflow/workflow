<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

final readonly class Observation
{
    public function __construct(
        public Status $status,
        public ?string $effectId,
        public bool $admissionClosed,
    ) {
    }
}
