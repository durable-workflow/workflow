<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use InvalidArgumentException;
use LogicException;
use Workflow\V2\StateMachine\Definition\PolicyProfile;

/** Payment keeps the common factual route; service access has a separate, fixed period policy. */
final class BillingPeriodPolicy
{
    public static function paymentProfile(BillingPeriod $period): PolicyProfile
    {
        $base = FundingCatalogue::payment(FundingCatalogue::IMMEDIATE);
        return PolicyProfile::forDefinition(
            $base->definition ?? throw new LogicException('The common Payment definition is unavailable.'),
            'period.' . $period->activationPolicy . ($period->order === null ? '.service' : '.order'),
            '1',
            $base->transitions,
            $base->conditions,
        );
    }

    /**
     * Pure projection only. A recorded host boundary must obtain and pin authoritative payment evidence.
     * A signal, a caller timestamp or this projection is not an admission/activation receipt.
     * Late payment never silently reactivates this example's expired service period.
     */
    public static function access(BillingPeriod $period, int $now, ?int $confirmedAt): array
    {
        if ($now < 0 || ($confirmedAt !== null && ($confirmedAt < 0 || $confirmedAt > $now))) {
            throw new InvalidArgumentException('The period observation times are malformed.');
        }
        $paidOnTime = $confirmedAt !== null && $confirmedAt < $period->paymentDeadline;
        $inPeriod = $now >= $period->startsAt && $now < $period->endsAt;
        $grace = $period->activationPolicy === BillingPeriod::GRACE && $now < $period->paymentDeadline;
        return [
            'period' => $period->subjectKey(),
            'deadline' => $period->paymentDeadline,
            'paidOnTime' => $paidOnTime,
            'latePayment' => $confirmedAt !== null && ! $paidOnTime,
            'access' => $inPeriod && ($paidOnTime || ($grace && $period->accessDuringGrace)),
            'state' => self::state($inPeriod, $paidOnTime, $grace),
        ];
    }

    private static function state(bool $inPeriod, bool $paidOnTime, bool $grace): string
    {
        return match (true) {
            ! $inPeriod => 'outside-period',
            $paidOnTime => 'paid',
            $grace => 'grace',
            default => 'unpaid',
        };
    }
}
