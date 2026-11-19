<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use InvalidArgumentException;

/** One provider observation for a recorded host boundary; historical facts and current permission remain separate. */
final readonly class FinancialSnapshot
{
    public function __construct(
        public FinancialDescriptor $descriptor,
        public int $observedAt,
        public Observation $authorization,
        public Observation $capture,
        public Observation $void,
        public Observation $refund,
        public bool $captureAdmissionClosed,
        public bool $captureInFlight,
    ) {
        if ($observedAt < 0) {
            throw new InvalidArgumentException('Financial observation time must be a nonnegative Unix timestamp.');
        }
    }

    /**
     * A missing confirmation never proves nonexecution; native admissions and raw observations remain authoritative.
     */
    public function facts(): array
    {
        $terms = AuthorizationTerms::fromArray($this->descriptor->authorize->terms);
        $authorized = $this->confirmed($this->descriptor->authorize, $this->authorization);
        $captured = $this->confirmed($this->descriptor->capture, $this->capture);
        $beforeExpiry = $terms->canAdmitCaptureAt($this->observedAt);
        $consistent = $this->consistent();
        return [
            'financial_observation_consistent' => $consistent,
            'authorization_confirmed' => $authorized,
            'authorization_admissible' => $consistent && $beforeExpiry && ! $this->authorization->admissionClosed
                && ! $this->captureAdmissionClosed && $this->authorization->status !== Status::Refused && ! $captured,
            'authorization_active' => $consistent && $authorized && $beforeExpiry && ! $this->captureAdmissionClosed
                && ! $this->captureInFlight && ! $captured,
            'authorization_expired' => ! $beforeExpiry,
            'capture_confirmed' => $captured,
            'capture_in_flight' => $this->captureInFlight,
            'capture_admission_closed' => $this->captureAdmissionClosed,
            'void_confirmed' => $this->confirmed($this->descriptor->void, $this->void),
            'refund_confirmed' => $this->confirmed($this->descriptor->refund, $this->refund),
        ];
    }

    public function toArray(): array
    {
        $observations = [];
        foreach (['authorization', 'capture', 'void', 'refund'] as $key) {
            $value = $this->{$key};
            $observations[$key] = [
                'status' => $value->status->value,
                'effectId' => $value->effectId,
                'admissionClosed' => $value->admissionClosed,
            ];
        }
        return [
            'descriptorFingerprint' => $this->descriptor->fingerprint(),
            'originalTerms' => $this->descriptor->authorize->terms,
            'observedAt' => $this->observedAt,
            'observations' => $observations,
            'facts' => $this->facts(),
        ];
    }

    private function confirmed(Request $request, Observation $observed): bool
    {
        return $observed->status === Status::Confirmed && $observed->effectId === 'effect-' . $request->identity->key();
    }

    private function consistent(): bool
    {
        foreach ([
            'authorize' => $this->authorization,
            'capture' => $this->capture,
            'void' => $this->void,
            'refund' => $this->refund,
        ] as $key => $observed) {
            $request = $this->descriptor->requests()[$key];
            if (($observed->status === Status::Confirmed) !== ($observed->effectId !== null)
                || ($observed->effectId !== null && $observed->effectId !== 'effect-' . $request->identity->key())) {
                return false;
            }
        }
        return true;
    }
}
