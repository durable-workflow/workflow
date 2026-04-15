<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Throwable;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\startActivity;
use Workflow\V2\Workflow;

#[Type('test-saga-parallel-compensation-workflow')]
final class TestSagaParallelCompensationWorkflow extends Workflow
{
    public function handle(): array
    {
        $this->setParallelCompensation(true);

        try {
            $flightId = activity(TestSagaBookingActivity::class, 'flight');
            $this->addCompensation(static fn () => startActivity(TestSagaCancelActivity::class, 'flight', $flightId));

            $hotelId = activity(TestSagaBookingActivity::class, 'hotel');
            $this->addCompensation(static fn () => startActivity(TestSagaCancelActivity::class, 'hotel', $hotelId));

            activity(TestFailingActivity::class);

            return [
                'flight' => $flightId,
                'hotel' => $hotelId,
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
