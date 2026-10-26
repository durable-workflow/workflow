<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Validation\ValidationException;
use Tests\NonDatabaseTestCase;
use Workflow\V2\Support\UpdateWaitPolicy;

final class UpdateWaitPolicyTest extends NonDatabaseTestCase
{
    public function testConfiguredCompletionTimeoutIsBounded(): void
    {
        config()->set('workflows.v2.update_wait.completion_timeout_seconds', 61);

        $this->assertSame(60, UpdateWaitPolicy::completionTimeoutSeconds());
        $this->assertSame(60, UpdateWaitPolicy::snapshot()['completion_timeout_seconds']);
    }

    public function testRequestedCompletionTimeoutAcceptsOnlyTheBoundedPositiveRange(): void
    {
        $this->assertNull(UpdateWaitPolicy::requestedTimeoutSeconds(null));
        $this->assertSame(1, UpdateWaitPolicy::requestedTimeoutSeconds(1));
        $this->assertSame(60, UpdateWaitPolicy::requestedTimeoutSeconds('60'));

        try {
            UpdateWaitPolicy::requestedTimeoutSeconds(61);
        } catch (ValidationException $exception) {
            $this->assertSame([
                'wait_timeout_seconds' => ['The wait_timeout_seconds field must not exceed 60 seconds.'],
            ], $exception->errors());

            return;
        }

        $this->fail('A completion wait above the maximum must be refused.');
    }

    public function testServiceOperationTimeoutAcceptsOnlyTheBoundedNonNegativeRange(): void
    {
        $this->assertNull(UpdateWaitPolicy::serviceOperationTimeoutSeconds(null));
        $this->assertSame(0, UpdateWaitPolicy::serviceOperationTimeoutSeconds(0));
        $this->assertSame(60, UpdateWaitPolicy::serviceOperationTimeoutSeconds(60));

        try {
            UpdateWaitPolicy::serviceOperationTimeoutSeconds(61);
        } catch (ValidationException $exception) {
            $this->assertSame([
                'wait_timeout_seconds' => ['The wait_timeout_seconds field must not exceed 60 seconds.'],
            ], $exception->errors());

            return;
        }

        $this->fail('A service-operation wait above the maximum must be refused.');
    }
}
