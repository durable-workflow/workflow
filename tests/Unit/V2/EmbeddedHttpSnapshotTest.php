<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PDO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class EmbeddedHttpSnapshotTest extends TestCase
{
    public function testQuotedTableNamesPreserveRowsAndDatabaseBytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'workflow-snapshot-');
        $this->assertIsString($path);

        try {
            $database = new PDO('sqlite:' . $path);
            $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $database->exec('CREATE TABLE workflow_plain (id INTEGER, value TEXT)');
            $database->exec('CREATE TABLE "workflow_quote""test" (id INTEGER, value TEXT)');
            $database->exec('CREATE TABLE jobs (id INTEGER)');
            $database->exec('CREATE TABLE failed_jobs (id INTEGER)');
            $database->exec("INSERT INTO workflow_plain VALUES (1, 'plain')");
            $database->exec("INSERT INTO \"workflow_quote\"\"test\" VALUES (2, 'quoteλ')");
            unset($database);
            $before = hash_file('sha256', $path);

            $process = new Process([
                PHP_BINARY,
                dirname(__DIR__, 3) . '/examples/embedded-http/qualification/snapshot.php',
                $path,
            ]);
            $process->setTimeout(10);
            $process->mustRun();
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame([['id' => 1, 'value' => 'plain']], $result['workflow_plain']);
            $this->assertSame([['id' => 2, 'value' => 'quoteλ']], $result['workflow_quote"test']);
            $this->assertSame([], $result['jobs']);
            $this->assertSame([], $result['failed_jobs']);
            $this->assertSame($before, hash_file('sha256', $path));
        } finally {
            unlink($path);
        }
    }
}
