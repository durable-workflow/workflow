<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use function Workflow\await;
use Workflow\SignalMethod;
use Workflow\Workflow;

final class TestTripleAwaitsWorkflow extends Workflow
{
    public bool $firstApproved = false;

    public bool $secondApproved = false;

    public bool $thirdApproved = false;

    #[SignalMethod]
    public function approveFirst(bool $value): void
    {
        $this->firstApproved = $value;
    }

    #[SignalMethod]
    public function approveSecond(bool $value): void
    {
        $this->secondApproved = $value;
    }

    #[SignalMethod]
    public function approveThird(bool $value): void
    {
        $this->thirdApproved = $value;
    }

    public function execute()
    {
        yield await(fn () => $this->firstApproved);
        yield await(fn () => $this->secondApproved);
        yield await(fn () => $this->thirdApproved);

        return 'all_approved';
    }
}
