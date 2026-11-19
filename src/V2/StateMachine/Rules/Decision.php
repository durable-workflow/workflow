<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Rules;

use InvalidArgumentException;

/**
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
final readonly class Decision
{
    /**
     * @var list<string>
     */
    public array $evidence;

    /**
     * @param list<string> $evidence
     */
    public function __construct(
        public Verdict $verdict,
        public string $reason,
        array $evidence = [],
    ) {
        if (trim($reason) === '' || ! array_is_list($evidence)) {
            throw new InvalidArgumentException('A decision requires a reason and a list of evidence references.');
        }
        foreach ($evidence as $reference) {
            if (! is_string($reference) || trim($reference) === '') {
                throw new InvalidArgumentException('Evidence references must be non-empty strings.');
            }
        }
        $this->evidence = array_map(static fn (string $reference): string => $reference, $evidence);
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
