<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Rules;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Facts and provenance collected by a recorded activity. This value performs no live reads. */
final readonly class RecordedFacts
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $evidence
     */
    public function __construct(
        public string $snapshotId,
        public array $values,
        public array $evidence,
    ) {
        PortableDescriptor::identifier($snapshotId, 'fact snapshot');
        $keys = array_keys($values);
        $evidenceKeys = array_keys($evidence);
        sort($keys, SORT_STRING);
        sort($evidenceKeys, SORT_STRING);
        if ($keys !== $evidenceKeys) {
            throw new InvalidArgumentException('Every recorded fact requires its exact evidence reference.');
        }
        foreach ($values as $key => $_) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Recorded facts require named fields.');
            }
            PortableDescriptor::identifier($key, 'fact name');
            $reference = $evidence[$key];
            if (! is_string($reference) || trim($reference) === '' || strlen($reference) > 512) {
                throw new InvalidArgumentException('Fact evidence must be a bounded immutable reference.');
            }
        }
        PortableDescriptor::encode($this->toArray());
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    /**
     * @return array{snapshotId: string, values: array<string, mixed>, evidence: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'snapshotId' => $this->snapshotId,
            'values' => $this->values,
            'evidence' => $this->evidence,
        ];
    }
}
