<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Rules;

use InvalidArgumentException;

final readonly class Decision
{
    /**
     * @param list<string> $evidence
     */
    public function __construct(
        public Verdict $verdict,
        public string $reason,
        public array $evidence = [],
    ) {
        if (trim($reason) === '' || ! array_is_list($evidence)) {
            throw new InvalidArgumentException('A decision requires a reason and a list of evidence references.');
        }
        foreach ($evidence as $reference) {
            if (! is_string($reference) || trim($reference) === '') {
                throw new InvalidArgumentException('Evidence references must be non-empty strings.');
            }
        }
    }

    /**
     * @return array{verdict: string, reason: string, evidence: list<string>}
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict->value,
            'reason' => $this->reason,
            'evidence' => $this->evidence,
        ];
    }

    public function satisfied(): bool
    {
        return $this->verdict === Verdict::Satisfied;
    }
}
