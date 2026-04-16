<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class FunctionsAutoloadCompatibilityTest extends TestCase
{
    public function testRootFunctionsFileAlsoRegistersV2Helpers(): void
    {
        $process = new Process([
            'php',
            '-r',
            <<<'PHP'
require_once __DIR__ . '/src/functions.php';
var_export(function_exists('Workflow\\V2\\signal'));
PHP
            ,
        ], dirname(__DIR__, 3));

        $process->mustRun();

        $this->assertSame('true', trim($process->getOutput()));
    }
}
