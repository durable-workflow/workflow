<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Execution;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Explicit authoritative evidence, not merely a successful callback return. */
final readonly class Resolution
{
    private function __construct(
        public bool $verified,
        public string $reason,
        public array $evidence,
        public bool $forwardSettled,
    ) {
        PortableDescriptor::identifier($reason, 'outcome reason');
        EvidenceReferences::validate($evidence);
        if ($verified && $evidence === []) {
            throw new InvalidArgumentException('Verified compensation requires authoritative evidence.');
        }
        PortableDescriptor::encode($this->toArray());
    }

    public static function verified(array $evidence, bool $forwardSettled): self
    {
        return new self(true, 'verified', $evidence, $forwardSettled);
    }

    public static function unknown(string $reason): self
    {
        return new self(false, $reason, [], false);
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
