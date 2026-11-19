<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/**
 * Shared state identity and meaning, independently of an integration's allowed edges.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class MachineState
{
    public function __construct(
        public string $id,
        public string $meaning
    ) {
        if (trim($id) === '' || trim($meaning) === '') {
            throw new InvalidArgumentException('A machine state requires its shared business meaning.');
        }
        PortableDescriptor::encode($this->toArray());
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'meaning' => $this->meaning,
        ];
    }
}
