<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Closure;
use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;
use function Workflow\V2\await;

#[Type('test-update-receive-ordinal-proof')]
#[Signal('done')]
final class TestUpdateReceiveOrdinalProofWorkflow extends Workflow
{
    public static ?Closure $arrival = null;

    public static int $arrivalBeforeCall = 0;

    public static array $observed = [];

    private array $taken = [];

    public function handle(): array
    {
        await('done');

        return $this->taken;
    }

    #[UpdateMethod]
    public function take(): string
    {
        for ($call = 0; $call < 2; ++$call) {
            if ($call === self::$arrivalBeforeCall && self::$arrival !== null) {
                (self::$arrival)();
            }
            $this->taken[] = $this->inbox('chat')->receiveOne()?->payload_reference;
        }
        self::$observed = $this->taken;

        return 'ack';
    }

    #[QueryMethod]
    public function taken(): array
    {
        return $this->taken;
    }
}
