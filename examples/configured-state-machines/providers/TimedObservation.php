<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;

/** The selected provider's original commit time, never the consumer's acknowledgement/lookup time. */
final readonly class TimedObservation
{
    public function __construct(
        public Request $request,
        public Observation $observation,
        public ?int $confirmedAt,
    ) {
        $confirmed = $observation->status === Status::Confirmed;
        if ($confirmed !== ($observation->effectId !== null) || $confirmed !== ($confirmedAt !== null)
            || ($confirmedAt !== null && $confirmedAt <= 0)
            || ($observation->effectId !== null && $observation->effectId !== 'effect-' . $request->identity->key())) {
            throw new InvalidArgumentException('A timed observation must preserve its original confirmed effect.');
        }
    }

    public function toArray(): array
    {
        return [
            'request' => $this->request->toArray(),
            'status' => $this->observation->status->value,
            'effectId' => $this->observation->effectId,
            'admissionClosed' => $this->observation->admissionClosed,
            'confirmedAt' => $this->confirmedAt,
        ];
    }

    /**
     * A recorded host read must still match the complete original assigned command.
     */
    public static function fromArray(array $value, Request $original): self
    {
        $keys = array_keys($value);
        sort($keys);
        if ($keys !== ['admissionClosed', 'confirmedAt', 'effectId', 'request', 'status']
            || ! is_array($value['request']) || ! is_string($value['status'])
            || ! is_bool($value['admissionClosed'])
            || ($value['confirmedAt'] !== null && ! is_int($value['confirmedAt']))
            || ($value['effectId'] !== null && ! is_string($value['effectId']))) {
            throw new InvalidArgumentException('The timed provider observation fields are malformed.');
        }
        $request = Request::fromArray($value['request']);
        $status = Status::tryFrom($value['status']);
        if ($request->fingerprint() !== $original->fingerprint() || $status === null) {
            throw new InvalidArgumentException('The timed receipt does not belong to this original payment.');
        }
        return new self(
            $request,
            new Observation($status, $value['effectId'], $value['admissionClosed']),
            $value['confirmedAt']
        );
    }
}
