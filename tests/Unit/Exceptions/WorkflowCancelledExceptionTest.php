<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use Error;
use Exception;
use PHPUnit\Framework\TestCase;
use Throwable;
use Workflow\V2\Exceptions\WorkflowCancelledException;

/**
 * Contract tests for the cancellation exception inheritance shape.
 *
 * Guards the pre-2.0 design decision that WorkflowCancelledException extends
 * {@see \Error} rather than {@see \Exception}, so that generic
 * ``catch (\Exception $e)`` blocks cannot accidentally swallow a cancellation
 * signal.
 */
final class WorkflowCancelledExceptionTest extends TestCase
{
    public function testNotCatchableViaException(): void
    {
        $caughtByException = false;
        $caughtByClass = false;

        try {
            throw new WorkflowCancelledException('stop');
        } catch (Exception $e) {
            $caughtByException = true;
        } catch (WorkflowCancelledException $e) {
            $caughtByClass = true;
        }

        $this->assertFalse(
            $caughtByException,
            'WorkflowCancelledException must not be catchable via catch (Exception)',
        );
        $this->assertTrue($caughtByClass);
    }

    public function testCatchableViaThrowable(): void
    {
        try {
            throw new WorkflowCancelledException('stop');
        } catch (Throwable $t) {
            $this->assertInstanceOf(WorkflowCancelledException::class, $t);

            return;
        }

        $this->fail('WorkflowCancelledException must be catchable via catch (Throwable)');
    }

    public function testInheritanceShape(): void
    {
        $this->assertTrue(is_subclass_of(WorkflowCancelledException::class, Error::class));
        $this->assertTrue(is_subclass_of(WorkflowCancelledException::class, Throwable::class));
        $this->assertFalse(is_subclass_of(WorkflowCancelledException::class, Exception::class));
    }
}
