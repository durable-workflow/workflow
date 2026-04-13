<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-saga-continue-with-error-workflow')]
final class TestSagaContinueWithErrorWorkflow extends Workflow
{
    public function handle(): array
    {
        $this->setContinueWithError(true);

        try {
            $flightId = activity(TestSagaBookingActivity::class, 'flight');
            $this->addCompensation(fn () => activity(TestSagaFailingCancelActivity::class, 'flight', $flightId));

            $hotelId = activity(TestSagaBookingActivity::class, 'hotel');
            $this->addCompensation(fn () => activity(TestSagaCancelActivity::class, 'hotel', $hotelId));

            activity(TestFailingActivity::class);

            return ['flight' => $flightId, 'hotel' => $hotelId];
        } catch (Throwable $e) {
            $this->compensate();

            return ['compensated' => true, 'reason' => $e->getMessage()];
        }
    }
}
