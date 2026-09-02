<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class PublicBoundaryCheckTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = sys_get_temp_dir() . '/workflow-public-boundary-' . bin2hex(random_bytes(6));
        mkdir($this->repo, 0777, true);

        $this->mustRun(['git', 'init'], $this->repo);
        $this->mustRun(['git', 'config', 'user.name', 'Durable Workflow'], $this->repo);
        $this->mustRun(['git', 'config', 'user.email', 'support@durable-workflow.com'], $this->repo);
        $this->mustRun(['git', 'branch', '-M', 'target'], $this->repo);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->repo);

        parent::tearDown();
    }

    public function testCommitMetadataScanAllowsBadTargetCommitReachableFromBaseline(): void
    {
        file_put_contents($this->repo . '/README.md', "clean baseline\n");
        $rootCommit = $this->commitAll('Initial public baseline');

        file_put_contents($this->repo . '/target.md', "historical target metadata debt\n");
        $this->commitAll($this->badTargetSubject());

        $this->mustRun(['git', 'checkout', '-b', 'candidate'], $this->repo);
        file_put_contents($this->repo . '/candidate.md', "clean candidate change\n");
        $this->commitAll('Add candidate change');

        $process = $this->runBoundary([
            'PUBLIC_BOUNDARY_GIT_RANGE' => $rootCommit . '..HEAD',
            'PUBLIC_BOUNDARY_GIT_BASELINE' => 'target',
        ]);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
    }

    public function testCommitMetadataScanRejectsBadCandidateCommitAfterBaseline(): void
    {
        file_put_contents($this->repo . '/README.md', "clean baseline\n");
        $targetCommit = $this->commitAll('Initial public baseline');

        $this->mustRun(['git', 'checkout', '-b', 'candidate'], $this->repo);
        file_put_contents($this->repo . '/candidate.md', "candidate change\n");
        $badCommit = $this->commitAll('Add candidate change', $this->badCandidateBody());

        $process = $this->runBoundary([
            'PUBLIC_BOUNDARY_GIT_RANGE' => $targetCommit . '..HEAD',
            'PUBLIC_BOUNDARY_GIT_BASELINE' => 'target',
        ]);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString(
            'public-boundary: forbidden commit metadata at ' . substr($badCommit, 0, 12),
            $process->getErrorOutput(),
        );
    }

    public function testSourceFileLeakPatternsRemainFullTreeFailures(): void
    {
        file_put_contents($this->repo . '/README.md', "clean baseline\n");
        $targetCommit = $this->commitAll('Initial public baseline');

        $this->mustRun(['git', 'checkout', '-b', 'candidate'], $this->repo);
        file_put_contents(
            $this->repo . '/candidate.md',
            implode("\n", [
                $this->patternFromHex('7a6f72706f726174696f6e2f'),
                $this->patternFromHex('2f686f6d652f6c61622f776f726b73706163652d6871'),
                $this->patternFromHex('5b63726f73732d7265706f2066726f6d20'),
            ]) . "\n",
        );
        $this->commitAll('Add candidate change');

        $process = $this->runBoundary([
            'PUBLIC_BOUNDARY_GIT_RANGE' => $targetCommit . '..HEAD',
            'PUBLIC_BOUNDARY_GIT_BASELINE' => 'target',
        ]);

        self::assertNotSame(0, $process->getExitCode());
        self::assertStringContainsString(
            'public-boundary: forbidden file content at candidate.md:1',
            $process->getErrorOutput(),
        );
        self::assertStringContainsString(
            'public-boundary: forbidden file content at candidate.md:2',
            $process->getErrorOutput(),
        );
        self::assertStringContainsString(
            'public-boundary: forbidden file content at candidate.md:3',
            $process->getErrorOutput(),
        );
    }

    private function commitAll(string $subject, ?string $body = null): string
    {
        $this->mustRun(['git', 'add', '.'], $this->repo);

        $command = ['git', 'commit', '-m', $subject];
        if ($body !== null) {
            $command[] = '-m';
            $command[] = $body;
        }

        $this->mustRun($command, $this->repo);

        return trim($this->mustRun(['git', 'rev-parse', 'HEAD'], $this->repo)->getOutput());
    }

    /**
     * @param array<string, string> $env
     */
    private function runBoundary(array $env): Process
    {
        $process = new Process(
            ['bash', $this->scriptPath(), $this->repo],
            dirname(__DIR__, 2),
            array_merge($this->stringEnvironment(), $env),
        );
        $process->setTimeout(20);
        $process->run();

        return $process;
    }

    /**
     * @param list<string> $command
     */
    private function mustRun(array $command, string $cwd): Process
    {
        $process = new Process($command, $cwd, $this->stringEnvironment());
        $process->setTimeout(20);
        $process->run();

        if (! $process->isSuccessful()) {
            self::fail(sprintf(
                "Command failed: %s\n%s%s",
                implode(' ', $command),
                $process->getErrorOutput(),
                $process->getOutput(),
            ));
        }

        return $process;
    }

    private function scriptPath(): string
    {
        return dirname(__DIR__, 2) . '/scripts/check-public-boundary.sh';
    }

    private function badTargetSubject(): string
    {
        return '[' . 'cross' . '-repo from server' . chr(35) . '000] Historical target metadata';
    }

    private function badCandidateBody(): string
    {
        return 'Loop' . '-ID: candidate-handoff';
    }

    private function patternFromHex(string $hex): string
    {
        $pattern = hex2bin($hex);
        self::assertIsString($pattern);

        return $pattern;
    }

    /**
     * @return array<string, string>
     */
    private function stringEnvironment(): array
    {
        $environment = array_merge($_SERVER, $_ENV);

        return array_filter($environment, static fn (mixed $value): bool => is_string($value));
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($directory);
    }
}
