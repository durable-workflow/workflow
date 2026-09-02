<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixtures\V1UpgradeWorkflow;
use Tests\SchemaTestCase;

final class V2UpgradeStatusCommandTest extends SchemaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()
            ->set('queue.default', 'redis');
        config()
            ->set('queue.connections.redis.queue', 'workflow-v1');
        config()
            ->set('workflows.v2.queue', 'workflow-v2');
    }

    public function testDrainFailsClosedUntilEveryV1RunIsTerminal(): void
    {
        $id = $this->seedV1Run('running');

        [$exitCode, $report] = $this->runStatus('drain');

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['ready']);
        $this->assertSame(['v1_open_runs_remain'], $report['findings']);
        $this->assertSame(1, $report['v1']['open_run_count']);
        $this->assertSame(['workflow-v1'], $report['v1']['queues']);

        DB::table('workflows')->where('id', $id)->update([
            'status' => 'completed',
        ]);

        [$exitCode, $report] = $this->runStatus('drain');

        $this->assertSame(0, $exitCode);
        $this->assertTrue($report['ready']);
        $this->assertSame(0, $report['v1']['open_run_count']);
    }

    public function testCoexistRequiresAnIsolatedEffectiveV2Queue(): void
    {
        $this->seedV1Run('waiting');

        [$exitCode, $report] = $this->runStatus('coexist');

        $this->assertSame(0, $exitCode);
        $this->assertTrue($report['ready']);
        $this->assertSame('workflow-v2', $report['embedded_v2']['queue']);
        $this->assertSame('v1', $report['v1']['history_owner']);
        $this->assertSame('embedded_v2', $report['embedded_v2']['history_owner']);
        $this->assertSame('none', $report['state_transfer']);
        $this->assertFalse($report['composer_change_migrates_history']);

        config()
            ->set('workflows.v2.queue', 'workflow-v1');

        [$exitCode, $report] = $this->runStatus('coexist');

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['ready']);
        $this->assertContains('v2_queue_not_isolated', $report['findings']);
    }

    public function testStrategyMustBeChosenExplicitly(): void
    {
        $output = new BufferedOutput();

        $exitCode = Artisan::call('workflow:v2:upgrade-status', [
            '--json' => true,
        ], $output);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--strategy=drain', $output->fetch());
    }

    private function seedV1Run(string $status): int
    {
        return DB::table('workflows')->insertGetId([
            'class' => V1UpgradeWorkflow::class,
            'status' => $status,
            'created_at' => '2026-08-20 08:00:00',
            'updated_at' => '2026-08-20 08:00:00',
        ]);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runStatus(string $strategy): array
    {
        $output = new BufferedOutput();
        $exitCode = Artisan::call('workflow:v2:upgrade-status', [
            '--strategy' => $strategy,
            '--json' => true,
        ], $output);
        $report = json_decode(trim($output->fetch()), true, 512, JSON_THROW_ON_ERROR);

        return [$exitCode, $report];
    }
}
