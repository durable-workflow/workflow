<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Execution;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/**
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final readonly class ForwardResult
{
    private function __construct(
        public bool $observed,
        public array $value,
        public array $evidence,
        public string $reason
    ) {
        PortableDescriptor::identifier($reason, 'forward reason');
        EvidenceReferences::validate($evidence);
        if ($observed && $evidence === []) {
            throw new InvalidArgumentException('An observed forward outcome requires evidence.');
        }
        PortableDescriptor::encode($this->toArray());
    }

    public static function observed(array $value, array $evidence): self
    {
        return new self(true, $value, $evidence, 'observed');
    }

    public static function unknown(string $reason): self
    {
        return new self(false, [], [], $reason);
    }

    public function toArray(): array
    {
        return [
            'schemaVersion' => 1,
            'observed' => $this->observed,
            'value' => $this->value,
            'evidence' => $this->evidence,
            'reason' => $this->reason,
        ];
    }
}
