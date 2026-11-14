<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Binding;

use InvalidArgumentException;
use LogicException;
use Workflow\V2\Workflow;

/**
 * Trusted host configuration selects a child definition by its pinned type and policy.
 *
 * @experimental Stored activity arguments never choose a class to autoload.
 */
final readonly class ChildDefinitionRegistry
{
    /**
     * @param array<string, array<string, class-string<Workflow>>> $definitions
     */
    public function __construct(
        private array $definitions = []
    ) {
        foreach ($definitions as $type => $policies) {
            if (! is_string($type) || trim($type) === '' || strlen($type) > 512 || ! is_array($policies)) {
                throw new InvalidArgumentException('Invalid registered child workflow type.');
            }
            foreach ($policies as $policy => $class) {
                if (! is_string($policy) || preg_match('/\A[0-9a-f]{64}\z/D', $policy) !== 1
                    || ! is_string($class) || trim($class) === '' || strlen($class) > 512) {
                    throw new InvalidArgumentException('Invalid pinned child policy or host definition.');
                }
            }
        }
    }

    /**
     * @return class-string<Workflow>
     */
    public function forBinding(BindingIdentity $identity): string
    {
        return $this->definitions[$identity->childWorkflowType][$identity->childPolicyFingerprint]
            ?? throw new LogicException('The pinned child definition is not registered by the host.');
    }
}
