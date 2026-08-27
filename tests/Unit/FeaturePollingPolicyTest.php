<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FeaturePollingPolicyTest extends TestCase
{
    public function testWorkflowPollingLoopsUseTheBoundedWaitHelper(): void
    {
        $featureTests = glob(dirname(__DIR__) . '/Feature/*.php');

        $this->assertIsArray($featureTests);
        $this->assertNotEmpty($featureTests);

        foreach ($featureTests as $featureTest) {
            $contents = file_get_contents($featureTest);

            $this->assertIsString($contents);
            $this->assertDoesNotMatchRegularExpression(
                '/while\s*\(\s*\$[A-Za-z_]\w*->running\(\)\s*\)\s*(?:;|\{)/',
                $contents,
                "{$featureTest} contains an unbounded workflow-running loop.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/while\s*\(\s*!\s*\$[A-Za-z_]\w*->isCanceled\(\)\s*\)\s*(?:;|\{)/',
                $contents,
                "{$featureTest} contains an unbounded workflow-cancellation loop.",
            );
        }
    }
}
