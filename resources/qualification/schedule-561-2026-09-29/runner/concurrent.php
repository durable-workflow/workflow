<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Contracts\ScheduleWorkflowStarter;
use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\PhpClassScheduleStarter;
use Workflow\V2\Support\ScheduleManager;
use Workflow\V2\Support\ScheduleStartResult;

$actor = $argv[1];
Carbon::setTestNowAndTimezone(Carbon::parse('2026-04-14T01:00:00Z')->setTimezone('UTC'), 'UTC');
app()->singleton(ScheduleWorkflowStarter::class, static fn () => new class implements ScheduleWorkflowStarter {
    public function start(
        WorkflowSchedule $schedule,
        ?DateTimeInterface $occurrenceTime,
        string $outcome,
        ?string $effectiveOverlapPolicy = null,
    ): ScheduleStartResult {
        usleep(800_000);

        return (new PhpClassScheduleStarter())->start($schedule, $occurrenceTime, $outcome, $effectiveOverlapPolicy);
    }
});

$barrier = __DIR__ . '/barrier-' . ($argv[2] ?? 'default');
file_put_contents($barrier . '/' . $actor . '.ready', 'ready');
$deadline = microtime(true) + 20;
while (! is_file($barrier . '/release') && microtime(true) < $deadline) {
    usleep(20_000);
}
if (! is_file($barrier . '/release')) {
    throw new RuntimeException('scheduler barrier timed out');
}

$result = ScheduleManager::tick();
$id = 'schedule-561-concurrent';
$schedule = WorkflowSchedule::query()->where('schedule_id', $id)->firstOrFail();
echo json_encode([
    'actor' => $actor,
    'result' => $result,
    'fires_count' => $schedule->fires_count,
    'history' => DB::table('workflow_schedule_history_events')
        ->where('schedule_id', $id)->orderBy('sequence')
        ->get(['sequence', 'event_type', 'workflow_instance_id'])->toArray(),
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
