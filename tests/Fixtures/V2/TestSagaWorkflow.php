<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type('test-saga-workflow')]
final class TestSagaWorkflow extends Workflow
{
    public function handle(bool $failOnThirdStep = true): array
    {
        try {
            $flightId = activity(TestSagaBookingActivity::class, 'flight');
            $this->addCompensation(static fn () => activity(TestSagaCancelActivity::class, 'flight', $flightId));

            $hotelId = activity(TestSagaBookingActivity::class, 'hotel');
            $this->addCompensation(static fn () => activity(TestSagaCancelActivity::class, 'hotel', $hotelId));

            if ($failOnThirdStep) {
                activity(TestFailingActivity::class);
            }

            $carId = activity(TestSagaBookingActivity::class, 'car');
            $this->addCompensation(static fn () => activity(TestSagaCancelActivity::class, 'car', $carId));

            return [
                'flight' => $flightId,
                'hotel' => $hotelId,
                'car' => $carId,
            ];
        } catch (Throwable $e) {
            $this->compensate();

            return [
                'compensated' => true,
                'reason' => $e->getMessage(),
            ];
        }
    }
}
