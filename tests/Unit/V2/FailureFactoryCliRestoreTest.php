<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Error;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class FailureFactoryCliRestoreTest extends TestCase
{
    public function testRestorationKeepsCliJsonCleanWithAllErrorsDisplayed(): void
    {
        $script = <<<'PHP'
require $argv[1];
$result = [];
foreach ([RuntimeException::class, Error::class] as $class) {
    $failure = Workflow\V2\Support\FailureFactory::restore([
        'class' => $class,
        'message' => 'example',
        'code' => 17,
        'file' => 'example.php',
        'line' => 1,
        'trace' => [],
        'properties' => [],
    ]);
    $result[$class] = [$failure::class, $failure->getMessage(), $failure->getCode(), $failure->getFile(), $failure->getLine(), $failure->getTrace()];
}
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([
            PHP_BINARY,
            '-d', 'error_reporting=-1',
            '-d', 'display_errors=1',
            '-d', 'log_errors=0',
            '-r', $script,
            dirname(__DIR__, 3) . '/vendor/autoload.php',
        ]);
        $process->mustRun();

        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame([
            RuntimeException::class => [RuntimeException::class, 'example', 17, 'example.php', 1, []],
            Error::class => [Error::class, 'example', 17, 'example.php', 1, []],
        ], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }
}
