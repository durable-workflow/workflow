<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Workflow\Ci\CommittedLocks\acceptedResults;
use function Workflow\Ci\CommittedLocks\command;
use function Workflow\Ci\CommittedLocks\main;

final class CommittedComposerLockAuditTest extends TestCase
{
    private ?string $fixture = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/scripts/ci/check-committed-composer-locks.php';
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->fixture, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->fixture);
        }
    }

    public function testExactHistoricalExceptionRemainsVisible(): void
    {
        [$result, $entry, $packages] = self::evidence();
        $this->assertSame(['PKSA-example'], acceptedResults($result, 1, $entry, $packages));
        $this->assertSame([], acceptedResults([
            'advisories' => [],
            'abandoned' => [],
        ], 0, [
            'accepted_advisories' => [],
        ], []));
    }

    public static function refusedChanges(): iterable
    {
        yield 'unknown advisory' => ['advisory'];
        yield 'other package' => ['package'];
        yield 'different locked version' => ['version'];
        yield 'different CVE' => ['cve'];
        yield 'abandoned package' => ['abandoned'];
        yield 'ignored advisory' => ['ignored'];
        yield 'filtered advisory' => ['filter'];
        yield 'missing advisory evidence' => ['missing'];
        yield 'non-advisory Composer failure' => ['command'];
        yield 'abandonment exit without evidence' => ['abandonment-exit'];
        yield 'successful exit with advisory evidence' => ['successful-exit'];
        yield 'obsolete historical exception' => ['obsolete'];
        yield 'exception without review condition' => ['review'];
    }

    #[DataProvider('refusedChanges')]
    public function testUnexpectedAuditEvidenceFailsClosed(string $change): void
    {
        [$result, $entry, $packages] = self::evidence();
        $exit = 1;
        switch ($change) {
            case 'advisory': $result['advisories']['symfony/mime'][0]['advisoryId'] = 'PKSA-new';
                break;
            case 'package': $result['advisories']['symfony/mime'][0]['packageName'] = 'other/package';
                break;
            case 'version': $packages['symfony/mime']['version'] = 'v8.1.8';
                break;
            case 'cve': $result['advisories']['symfony/mime'][0]['cve'] = 'CVE-other';
                break;
            case 'abandoned': $result['abandoned'] = [
                'old/package' => null,
            ];
                break;
            case 'ignored': $result['ignored-advisories'] = ['PKSA-hidden'];
                break;
            case 'filter': $result['filter'] = [
                'severity' => 'medium',
            ];
                break;
            case 'missing': unset($result['advisories']);
                break;
            case 'command': $exit = 10;
                break;
            case 'abandonment-exit': $exit = 2;
                break;
            case 'successful-exit': $exit = 0;
                break;
            case 'obsolete': $result['advisories'] = [];
                $exit = 0;
                break;
            case 'review': unset($entry['accepted_advisories'][0]['review_condition']);
                break;
        }
        $this->expectException(RuntimeException::class);
        acceptedResults($result, $exit, $entry, $packages);
    }

    public static function changedInventory(): iterable
    {
        yield 'new committed lock' => ['new'];
        yield 'removed committed lock' => ['removed'];
        yield 'changed historical bytes' => ['historical'];
        yield 'changed published source' => ['reference'];
    }

    #[DataProvider('changedInventory')]
    public function testUnreviewedInventoryChangesFailClosed(string $change): void
    {
        $root = $this->fixture = sys_get_temp_dir() . '/workflow-lock-audit-' . bin2hex(random_bytes(8));
        mkdir($root . '/resources', 0700, true);
        $lock = [
            'packages' => [[
                'name' => 'durable-workflow/workflow',
                'source' => [
                    'reference' => str_repeat('a', 40),
                ],
            ]],
        ];
        file_put_contents($root . '/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
        [$result, $exception] = self::evidence();
        $entry = [
            'workflow_reference' => str_repeat('a', 40),
            'accepted_advisories' => [],
        ];
        if ($change === 'historical') {
            $entry['accepted_advisories'] = $exception['accepted_advisories'];
            $entry['preserved_sha256'] = hash_file('sha256', $root . '/composer.lock');
            file_put_contents($root . '/composer.lock', "\n", FILE_APPEND);
        } elseif ($change === 'reference') {
            $entry['workflow_reference'] = str_repeat('b', 40);
        }
        file_put_contents($root . '/resources/committed-composer-lock-audit.json', json_encode([
            'schema' => 'durable-workflow.committed-composer-lock-audit/v1',
            'locks' => [
                'composer.lock' => $entry,
            ],
        ], JSON_THROW_ON_ERROR));
        $this->assertSame(0, command(['git', 'init', '--quiet'], $root)[0]);
        if ($change === 'new') {
            mkdir($root . '/new');
            copy($root . '/composer.lock', $root . '/new/composer.lock');
            $this->assertSame(0, command(['git', 'add', '--', 'composer.lock', 'new/composer.lock'], $root)[0]);
        } elseif ($change !== 'removed') {
            $this->assertSame(0, command(['git', 'add', '--', 'composer.lock'], $root)[0]);
        }
        $this->expectException(RuntimeException::class);
        main($root, true);
    }

    private static function evidence(): array
    {
        return [
            [
                'advisories' => [
                    'symfony/mime' => [[
                        'advisoryId' => 'PKSA-example',
                        'packageName' => 'symfony/mime',
                        'cve' => 'CVE-example',
                    ]],
                ],
                'abandoned' => [],
            ],
            [
                'accepted_advisories' => [[
                    'id' => 'PKSA-example',
                    'package' => 'symfony/mime',
                    'version' => 'v8.1.7',
                    'cve' => 'CVE-example',
                    'owner' => 'owning issue',
                    'reason' => 'preserved input',
                    'review_condition' => 'review before reuse',
                ]],
            ],
            [
                'symfony/mime' => [
                    'version' => 'v8.1.7',
                ],
            ],
        ];
    }
}
