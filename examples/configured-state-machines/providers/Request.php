<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Immutable command terms; the simulator does not choose the application's money or inventory policy. */
final readonly class Request
{
    public array $terms;

    public function __construct(
        public Identity $identity,
        public string $action,
        array $terms,
        public ?Identity $target = null,
    ) {
        if (! in_array($action, ['capture', 'refund', 'book', 'cancel-booking', 'reserve', 'release'], true)) {
            throw new InvalidArgumentException('The synthetic provider action is unavailable.');
        }
        $undo = in_array($action, ['refund', 'cancel-booking', 'release'], true);
        if ($undo !== ($target !== null) || ($target !== null && (! $identity->sameOwner($target)
            || $identity->key() === $target->key()))) {
            throw new InvalidArgumentException('Compensation must name another original operation of the same owner.');
        }
        $this->terms = PortableDescriptor::snapshot($terms);
        PortableDescriptor::encode($this->toArray());
    }

    public function toArray(): array
    {
        return [
            'identity' => $this->identity->toArray(),
            'action' => $this->action,
            'terms' => $this->terms,
            'target' => $this->target?->toArray(),
        ];
    }

    public function fingerprint(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['action', 'identity', 'target', 'terms'] || ! is_string($value['action'])
            || ! is_array($value['identity']) || ! is_array($value['terms'])
            || ($value['target'] !== null && ! is_array($value['target']))) {
            throw new InvalidArgumentException('The provider request fields are malformed.');
        }
        return new self(
            Identity::fromArray($value['identity']),
            $value['action'],
            $value['terms'],
            $value['target'] === null ? null : Identity::fromArray($value['target']),
        );
    }
}
