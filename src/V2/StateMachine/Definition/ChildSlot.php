<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Definition;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/**
 * A named machine-level slot; native admission and joint ownership remain host-authorized.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class ChildSlot
{
    /**
     * @var non-empty-list<string>
     */
    public array $hostingStates;

    /**
     * @param non-empty-list<string> $hostingStates
     */
    public function __construct(
        public string $key,
        array $hostingStates,
        public string $childMachine,
        public string $childDefinitionVersion,
        public string $requiredOutcome
    ) {
        foreach ([$key, $childMachine, $childDefinitionVersion, $requiredOutcome] as $part) {
            if (trim($part) === '') {
                throw new InvalidArgumentException('Child slot identities must not be empty.');
            }
        }
        if ($hostingStates === [] || ! array_is_list($hostingStates)) {
            throw new InvalidArgumentException('A child slot requires declared hosting states.');
        }
        foreach ($hostingStates as $state) {
            if (! is_string($state) || trim($state) === '') {
                throw new InvalidArgumentException('Hosting state identifiers must be strings.');
            }
        }
        if (count(array_unique($hostingStates)) !== count($hostingStates)) {
            throw new InvalidArgumentException('A child slot cannot repeat a hosting state.');
        }
        $this->hostingStates = array_map(static fn (string $state): string => $state, $hostingStates);
        PortableDescriptor::encode($this->toArray());
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'hostingStates' => $this->hostingStates,
            'childMachine' => $this->childMachine,
            'childDefinitionVersion' => $this->childDefinitionVersion,
            'requiredOutcome' => $this->requiredOutcome,
        ];
    }
}
