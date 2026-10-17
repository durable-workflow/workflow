<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\TestCase;
use Tests\Performance\ReplayProfileProgress;

final class ReplayProfileProgressTest extends TestCase
{
    public function testLatestProgressReplacesPriorStateAndCleansItsPrivateDirectory(): void
    {
        $progress = new ReplayProfileProgress(9, 2);
        $directory = dirname($progress->path());
        try {
            $progress->report('before_batch', 0, 3, 1, 1);
            $before = json_decode(file_get_contents($progress->path()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('before_batch', $before['phase']);
            $progress->report('after_batch', 8, 27, 19, 99);
            $after = json_decode(file_get_contents($progress->path()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('after_batch', $after['phase']);
            $this->assertSame(8, $after['iteration']);
            $this->assertSame(27, $after['activity_effects']);
            $this->assertCount(1, array_diff(scandir($directory), ['.', '..']));
            $this->assertLessThan(1024, filesize($progress->path()));
            if (PHP_OS_FAMILY !== 'Windows') {
                $this->assertSame(0700, fileperms($directory) & 0777);
                $this->assertSame(0600, fileperms($progress->path()) & 0777);
            }
        } finally {
            $progress->close();
        }
        $this->assertFileDoesNotExist($progress->path());
        $this->assertDirectoryDoesNotExist($directory);
        $progress->close();
    }
}
