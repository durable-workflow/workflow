<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Workflow\V2\Support\DefaultWorkflowTaskBridge;
use Workflow\V2\Support\WorkerProtocolVersion;

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

    public function testContinueAsNewCommandDoesNotCarryATargetBuildYet(): void
    {
        $normalize = new ReflectionMethod(DefaultWorkflowTaskBridge::class, 'normalizeContinueAsNewCommand');

        foreach (['promoted', 'inherited'] as $target) {
            $command = $normalize->invoke(null, [
                'type' => 'continue_as_new',
                'arguments' => null,
                'target_build' => $target,
            ]);

            $this->assertIsArray($command);
            $this->assertArrayNotHasKey(
                'target_build',
                $command,
                'Accepting target_build requires the closed-enum validation and capability gate in '
                . self::DOCUMENT . '; replace this guard with those tests.',
            );
        }
    }
}
