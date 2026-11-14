<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Binding;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\StartedChildWorkflow;
use Workflow\V2\Support\ChildWorkflowCall;
use Workflow\V2\Support\StartChildWorkflowCall;
use Workflow\V2\Support\WorkflowFiberContext;

/** @experimental An immutable descriptor, not proof of authorization or admission. */
final readonly class ChildReservation
{
    public function __construct(
        public BindingIdentity $identity,
        public string $workflowClass,
        public string $definitionFingerprint,
        public string $authorizationVersion,
    ) {
        if ($workflowClass === '' || strlen($workflowClass) > 512
            || trim($authorizationVersion) === '' || strlen($authorizationVersion) > 128
            || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $definitionFingerprint) !== 1) {
            throw new InvalidArgumentException('Invalid child reservation definition or authorization version.');
        }
        PortableDescriptor::encode($this->toArray());
    }

    public function childInstanceId(): string
    {
        return 'dw-bound-child-' . $this->identity->key();
    }

    /**
     * Start through the original parent's native command/history boundary.
     */
    public function start(mixed ...$arguments): StartedChildWorkflow
    {
        return WorkflowFiberContext::suspend(new StartChildWorkflowCall(new ChildWorkflowCall(
            $this->workflowClass,
            $arguments,
            $this->identity->childOptions,
            $this,
        )));
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public static function inputFingerprint(array $arguments): string
    {
        return hash('sha256', PortableDescriptor::encode($arguments));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'identity' => $this->identity->toArray(),
            'workflowClass' => $this->workflowClass,
            'definitionFingerprint' => $this->definitionFingerprint,
            'authorizationVersion' => $this->authorizationVersion,
        ];
    }

    /**
     * @param array<string, mixed> $value
     */
    public static function fromArray(array $value): self
    {
        if (count($value) !== 5 || ($value['version'] ?? null) !== 1
            || ! is_array($value['identity'] ?? null)
            || ! is_string($value['workflowClass'] ?? null)
            || ! is_string($value['definitionFingerprint'] ?? null)
            || ! is_string($value['authorizationVersion'] ?? null)) {
            throw new InvalidArgumentException('Unsupported or malformed child reservation.');
        }
        return new self(
            BindingIdentity::fromArray($value['identity']),
            $value['workflowClass'],
            $value['definitionFingerprint'],
            $value['authorizationVersion'],
        );
    }
}
