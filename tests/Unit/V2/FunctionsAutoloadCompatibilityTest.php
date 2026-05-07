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
$functions = [
    'Workflow\\V2\\signal',
    'Workflow\\V2\\parallel',
    'Workflow\\V2\\localActivity',
    'Workflow\\V2\\workerSession',
];
var_export(array_reduce(
    $functions,
    static fn (bool $ok, string $function): bool => $ok && function_exists($function),
    true,
));
PHP
            ,
        ], dirname(__DIR__, 3));

        $process->mustRun();

        $this->assertSame('true', trim($process->getOutput()));
    }
}
