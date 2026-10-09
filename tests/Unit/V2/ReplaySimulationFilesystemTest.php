<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\Support\ReplaySimulation;
use Workflow\V2\Support\ReplayVerification;

final class ReplaySimulationFilesystemTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/replay-directory-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->directory));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $path) {
                unlink($path);
            }

            rmdir($this->directory);
        }

        parent::tearDown();
    }

    #[DataProvider('reportOptions')]
    public function testMissingDirectoryBlocksPromotionWithoutInventingCheckedBundles(
        bool $skipReplay,
        bool $strictWarnings,
    ): void {
        $missing = $this->directory . '/missing';
        $simulation = new ReplaySimulation();

        $report = $simulation->simulateDirectory($missing, skipReplay: $skipReplay, strictWarnings: $strictWarnings);

        $this->assertSame(ReplayVerification::SIMULATION_REPORT_SCHEMA, $report['schema']);
        $this->assertSame(ReplayVerification::SIMULATION_REPORT_SCHEMA_VERSION, $report['schema_version']);
        $this->assertSame('failed', $report['verdict']);
        $this->assertSame('block_and_investigate', $report['promotion_decision']);
        $this->assertSame([
            'total' => 0,
            'ok' => 0,
            'warning' => 0,
            'drifted' => 0,
            'failed' => 0,
        ], $report['summary']);
        $this->assertSame([], $report['bundles']);
        $this->assertSame([$missing], $report['missing_bundles']);
        $this->assertSame('Bundle directory [' . $missing . '] does not exist.', $report['error']);
        $this->assertSame(0, $report['evidence']['bundle_count']);
        $this->assertSame(1, $report['evidence']['missing_bundle_count']);
        $this->assertSame(0, $report['evidence']['integrity_checked_count']);
        $this->assertSame(0, $report['evidence']['replay_checked_count']);
        $this->assertSame($skipReplay, $report['evidence']['replay_skipped']);
        $this->assertSame($strictWarnings, $report['evidence']['strict_warnings']);
        $this->assertSame($report, $simulation->simulateDirectory(
            $missing,
            skipReplay: $skipReplay,
            strictWarnings: $strictWarnings,
        ));
        $this->assertDirectoryDoesNotExist($missing);
        $this->assertSame([], glob($this->directory . '/*'));
    }

    #[DataProvider('reportOptions')]
    public function testUnreadableBundleRetainsItsIdentityAndHonestUncheckedEvidence(
        bool $skipReplay,
        bool $strictWarnings,
    ): void {
        $target = $this->directory . '/missing-target';
        $path = $this->directory . '/unreadable.json';
        $neighbor = $this->directory . '/notes.txt';
        $notes = "Leave this customer note unchanged.\n";
        $this->assertTrue(symlink($target, $path));
        $this->assertSame(strlen($notes), file_put_contents($neighbor, $notes));
        $simulation = new ReplaySimulation();

        $report = $simulation->simulateDirectory(
            $this->directory,
            skipReplay: $skipReplay,
            strictWarnings: $strictWarnings,
        );

        $this->assertSame('failed', $report['verdict']);
        $this->assertSame('block_and_investigate', $report['promotion_decision']);
        $this->assertSame([
            'total' => 1,
            'ok' => 0,
            'warning' => 0,
            'drifted' => 0,
            'failed' => 1,
        ], $report['summary']);
        $this->assertSame([], $report['missing_bundles']);
        $this->assertSame(1, $report['evidence']['bundle_count']);
        $this->assertSame(0, $report['evidence']['missing_bundle_count']);
        $this->assertSame(0, $report['evidence']['integrity_checked_count']);
        $this->assertSame(0, $report['evidence']['replay_checked_count']);
        $this->assertSame($skipReplay, $report['evidence']['replay_skipped']);
        $this->assertSame($strictWarnings, $report['evidence']['strict_warnings']);
        $this->assertCount(1, $report['bundles']);
        $this->assertSame([
            'bundle_path' => $path,
            'verdict' => 'failed',
            'promotion_decision' => 'block_and_investigate',
            'evidence' => [
                'integrity_checked' => false,
                'integrity_status' => null,
                'integrity_finding_count' => 0,
                'replay_checked' => false,
                'replay_status' => null,
                'replay_skipped' => $skipReplay,
                'strict_warnings' => $strictWarnings,
            ],
            'integrity' => null,
            'replay_diff' => null,
            'error' => [
                'class' => 'RuntimeException',
                'message' => 'Bundle file [' . $path . '] could not be read.',
            ],
        ], $report['bundles'][0]);
        $this->assertSame($report, $simulation->simulateDirectory(
            $this->directory,
            skipReplay: $skipReplay,
            strictWarnings: $strictWarnings,
        ));
        $this->assertTrue(is_link($path));
        $this->assertSame($target, readlink($path));
        $this->assertFileDoesNotExist($target);
        $this->assertSame($notes, file_get_contents($neighbor));
    }

    public static function reportOptions(): array
    {
        return [
            'replay, ordinary warnings' => [false, false],
            'integrity only, ordinary warnings' => [true, false],
            'replay, strict warnings' => [false, true],
            'integrity only, strict warnings' => [true, true],
        ];
    }
}
