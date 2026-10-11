<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\V2\TestNexusWaitingCallerWorkflow;
use Tests\Support\NexusCallerDeliveryScenario;
use Tests\TestCase;

final class V2NexusCallerDeliveryTest extends TestCase
{
    use NexusCallerDeliveryScenario;

    public function testWaitingEmbeddedCallerReceivesCommittedServiceResult(): void
    {
        self::stopWorkers();
        Queue::fake();

        $this->assertWaitingNexusCallerReceivesResult(TestNexusWaitingCallerWorkflow::class, ['world'], [
            'greeting' => 'hello world',
        ]);
    }
}
