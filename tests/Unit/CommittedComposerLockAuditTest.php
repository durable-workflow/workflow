<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Workflow\Ci\CommittedLocks\acceptedResults;
use function Workflow\Ci\CommittedLocks\command;
use function Workflow\Ci\CommittedLocks\main;
use function Workflow\Ci\CommittedLocks\reviewedPackages;

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
                $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
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
            'filter' => [],
            'cooldown' => [],
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
        yield 'delayed package evidence' => ['cooldown'];
        yield 'malformed optional evidence' => ['null-filter'];
        yield 'unknown report field' => ['unknown-field'];
        yield 'duplicate advisory evidence' => ['duplicate'];
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
            case 'cooldown': $result['cooldown'] = ['deferred/package'];
                break;
            case 'null-filter': $result['filter'] = null;
                break;
            case 'unknown-field': $result['unreviewed'] = [];
                break;
            case 'duplicate': $result['advisories']['symfony/mime'][] = $result['advisories']['symfony/mime'][0];
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

    public function testTrustRefusalPreventsTheComposerAudit(): void
    {
        $root = $this->fixture = sys_get_temp_dir() . '/workflow-lock-audit-' . bin2hex(random_bytes(8));
        mkdir($root . '/resources', 0700, true);
        file_put_contents($root . '/composer.lock', '{"packages":[]}');
        file_put_contents($root . '/resources/committed-composer-lock-audit.json', json_encode([
            'schema' => 'durable-workflow.committed-composer-lock-audit/v1',
            'locks' => [
                'composer.lock' => [
                    'workflow_reference' => null,
                    'accepted_advisories' => [],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        $this->assertSame(0, command(['git', 'init', '--quiet'], $root)[0]);
        $this->assertSame(0, command(['git', 'add', '--', 'composer.lock'], $root)[0]);
        $verifier = $root . '/refuse.php';
        $composer = $root . '/composer';
        file_put_contents($verifier, '<?php fwrite(STDERR, "untrusted transport"); exit(1);');
        file_put_contents($composer, "#!/bin/sh\ntouch audit-started\n");
        chmod($composer, 0700);
        $oldVerifier = getenv('COMPOSER_AUDIT_VERIFIER');
        $oldComposer = getenv('COMPOSER_AUDIT_BINARY');
        putenv('COMPOSER_AUDIT_VERIFIER=' . $verifier);
        putenv('COMPOSER_AUDIT_BINARY=' . $composer);
        try {
            main($root, false);
            $this->fail('A rejected Composer transport must prevent audit startup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Composer trust refused', $exception->getMessage());
            $this->assertFileDoesNotExist($root . '/audit-started');
        } finally {
            putenv($oldVerifier === false ? 'COMPOSER_AUDIT_VERIFIER' : 'COMPOSER_AUDIT_VERIFIER=' . $oldVerifier);
            putenv($oldComposer === false ? 'COMPOSER_AUDIT_BINARY' : 'COMPOSER_AUDIT_BINARY=' . $oldComposer);
        }
    }

    public static function changedPackageAuthority(): iterable
    {
        yield 'different source type' => ['source', 'type', 'svn'];
        yield 'different source URL' => ['source', 'url', 'https://github.com/other/workflow.git'];
        yield 'source URL user info' => ['source', 'url', 'https://user@github.com/durable-workflow/workflow.git'];
        yield 'unreviewed source mirror' => ['source', 'mirrors', ['https://example.com/workflow.git']];
        yield 'different dist type' => ['dist', 'type', 'tar'];
        yield 'dist-only reference change' => ['dist', 'reference', str_repeat('b', 40)];
        yield 'alternate dist authority' => ['dist', 'url', 'https://example.com/' . str_repeat('a', 40)];
        yield 'other GitHub repository' => [
            'dist',
            'url',
            'https://api.github.com/repos/other/workflow/zipball/' . str_repeat('a', 40),
        ];
        yield 'dist URL user info' => [
            'dist',
            'url',
            'https://user@api.github.com/repos/durable-workflow/workflow/zipball/' . str_repeat('a', 40),
        ];
        yield 'different dist checksum' => ['dist', 'shasum', str_repeat('c', 40)];
        yield 'null dist checksum' => ['dist', 'shasum', null];
        yield 'unreviewed download mirror' => ['dist', 'mirrors', ['https://example.com/workflow.zip']];
    }

    #[DataProvider('changedPackageAuthority')]
    public function testChangedDownloadAuthorityFailsClosed(string $section, string $field, mixed $value): void
    {
        $package = self::workflowPackage();
        $package[$section][$field] = $value;
        $file = $this->packageLock([
            'packages' => [$package],
        ]);
        $this->expectException(RuntimeException::class);
        reviewedPackages($file, [
            'workflow_reference' => str_repeat('a', 40),
            'accepted_advisories' => [],
        ]);
    }

    public static function invalidPackageRecords(): iterable
    {
        $package = self::workflowPackage();
        yield 'missing Workflow' => [[
            'packages' => [],
        ]];
        yield 'duplicate in section' => [[
            'packages' => [$package, $package],
        ]];
        yield 'duplicate across sections' => [[
            'packages' => [$package],
            'packages-dev' => [$package],
        ]];
        yield 'duplicate unrelated package' => [[
            'packages' => [
                $package, [
                    'name' => 'other/package',
                ]],
            'packages-dev' => [[
                'name' => 'other/package',
            ]],
        ]];
        yield 'missing packages section' => [[]];
        yield 'scalar packages section' => [[
            'packages' => false,
        ]];
        yield 'null dev section' => [[
            'packages' => [$package],
            'packages-dev' => null,
        ]];
        yield 'non-list section' => [[
            'packages' => [
                'named' => $package,
            ],
        ]];
        yield 'scalar package' => [[
            'packages' => [$package, false],
        ]];
        yield 'nameless package' => [[
            'packages' => [$package, []],
        ]];
        yield 'non-string package name' => [[
            'packages' => [
                $package, [
                    'name' => 12,
                ]],
        ]];
        yield 'empty package name' => [[
            'packages' => [
                $package, [
                    'name' => '',
                ]],
        ]];
    }

    #[DataProvider('invalidPackageRecords')]
    public function testMalformedOrDuplicatePackagesFailClosed(array $lock): void
    {
        $file = $this->packageLock($lock);
        $this->expectException(RuntimeException::class);
        reviewedPackages($file, [
            'workflow_reference' => str_repeat('a', 40),
            'accepted_advisories' => [],
        ]);
    }

    public function testExactPublishedPackageAndNullAbsenceAreAccepted(): void
    {
        $package = self::workflowPackage();
        $file = $this->packageLock([
            'packages' => [],
            'packages-dev' => [$package],
        ]);
        $this->assertSame([
            'durable-workflow/workflow' => $package,
        ], reviewedPackages($file, [
            'workflow_reference' => str_repeat('a', 40),
            'accepted_advisories' => [],
        ]));
        file_put_contents($file, '{"packages":[]}');
        $this->assertSame([], reviewedPackages($file, [
            'workflow_reference' => null,
            'accepted_advisories' => [],
        ]));
        file_put_contents($file, json_encode([
            'packages' => [$package],
        ], JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        reviewedPackages($file, [
            'workflow_reference' => null,
            'accepted_advisories' => [],
        ]);
    }

    private function packageLock(array $lock): string
    {
        $this->fixture = sys_get_temp_dir() . '/workflow-lock-audit-' . bin2hex(random_bytes(8));
        mkdir($this->fixture, 0700);
        $file = $this->fixture . '/composer.lock';
        file_put_contents($file, json_encode($lock, JSON_THROW_ON_ERROR));

        return $file;
    }

    private static function workflowPackage(): array
    {
        return [
            'name' => 'durable-workflow/workflow',
            'source' => [
                'type' => 'git',
                'url' => 'https://github.com/durable-workflow/workflow.git',
                'reference' => str_repeat('a', 40),
            ],
            'dist' => [
                'type' => 'zip',
                'url' => 'https://api.github.com/repos/durable-workflow/workflow/zipball/' . str_repeat('a', 40),
                'reference' => str_repeat('a', 40),
                'shasum' => '',
            ],
        ];
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
