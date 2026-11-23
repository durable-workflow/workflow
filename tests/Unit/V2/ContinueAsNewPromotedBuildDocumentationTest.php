<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use function Workflow\V2\continueAsNewWithTarget;
use Workflow\V2\Exceptions\ContinueAsNewTargetException;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\WorkerProtocolVersion;
use Workflow\V2\Support\WorkflowExecutor;

/**
 * Keeps the continue-as-new promoted-build contract discoverable and pins its
 * activation gate: until the embedded and native implementations pass every
 * vector in the contract, no runtime may advertise the target-build
 * capability or carry `target_build` on a continue-as-new command. The
 * Markdown body is intentionally not parsed so editorial changes do not
 * require synchronized test changes.
 */
final class ContinueAsNewPromotedBuildDocumentationTest extends TestCase
{
    private const DOCUMENT = 'docs/architecture/continue-as-new-promoted-build.md';

    private const CAPABILITY = 'continue_as_new_target_build';

    public function testContractDocumentExists(): void
    {
        $this->assertFileExists(dirname(__DIR__, 3) . '/' . self::DOCUMENT);
    }

    public function testRuntimeDoesNotAdvertiseTheTargetBuildCapabilityYet(): void
    {
        $constants = (new ReflectionClass(WorkerProtocolVersion::class))->getConstants();

        $this->assertNotContains(
            self::CAPABILITY,
            $constants,
            'Advertising continue_as_new_target_build requires the closed target, refusal and replay vectors '
            . 'in ' . self::DOCUMENT . ' to pass; replace this guard with those tests.',
        );
    }

    public function testStandaloneBridgeRefusesAnExplicitTargetBeforeAdmission(): void
    {
        $bridge = new DefaultWorkflowTaskBridge(new WorkflowExecutor());
        foreach ([
            'promoted',
            'inherited',
            null,
            true,
            false,
            0,
            1,
            [],
            new \stdClass(),
            '',
            'Promoted',
            'latest',
            'promoted ',
        ] as $target) {
            $result = $bridge->completeForProtocol('never-load-task', [[
                'type' => 'continue_as_new',
                'arguments' => null,
                'target_build' => $target,
            ]], WorkerProtocolVersion::VERSION);
            $this->assertSame(
                in_array(
                    $target,
                    ['promoted', 'inherited'],
                    true
                ) ? 'unsupported_target_build' : 'invalid_target_build',
                $result['reason'],
            );
            $this->assertFalse($result['completed']);
            $this->assertFalse($result['retryable']);
            $this->assertFalse($result['request_admitted']);
        }
    }

    public function testAuthoringFailsBeforeSuspendingForInvalidOrUnnegotiatedTargets(): void
    {
        foreach (['promoted', 'inherited', null, true, 0, [], new \stdClass(), 'Promoted', 'promoted '] as $target) {
            try {
                continueAsNewWithTarget($target, 'state');
                $this->fail('Unnegotiated continuation was authored.');
            } catch (ContinueAsNewTargetException $exception) {
                $this->assertSame(
                    in_array(
                        $target,
                        ['promoted', 'inherited'],
                        true
                    ) ? 'unsupported_target_build' : 'invalid_target_build',
                    $exception->reason,
                );
            }
        }
    }
}
