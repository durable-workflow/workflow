<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-saga-success-workflow')]
final class TestSagaSuccessWorkflow extends Workflow
{
    public function handle(): array
    {
        $flightId = activity(TestSagaBookingActivity::class, 'flight');
        $this->addCompensation(static fn () => activity(TestSagaCancelActivity::class, 'flight', $flightId));

        $hotelId = activity(TestSagaBookingActivity::class, 'hotel');
        $this->addCompensation(static fn () => activity(TestSagaCancelActivity::class, 'hotel', $hotelId));

        return [
            'flight' => $flightId,
            'hotel' => $hotelId,
        ];
    }
}
