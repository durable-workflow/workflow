<?php
declare(strict_types=1);
$profile = $argv[1];
$read = static fn (string $suffix): array => json_decode(file_get_contents('/evidence/'.$profile.'-'.$suffix.'.json'), true, 512, JSON_THROW_ON_ERROR);
$prepare = $read('prepare');
$finish = $read('finish');
$before = $read('before');
$after = $read('after');
$check = static function (bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$run = $prepare['original']['run_id'];
$check($prepare['pass'] && $finish['pass'], 'HTTP driver failed');
$check($finish['original'] === $prepare['original'], 'Original run changed');
$select = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $row): bool => ($row['workflow_run_id'] ?? null) === $run));
$prefix = $select($before['workflow_history_events']);
$events = $select($after['workflow_history_events']);
$check(count($prefix) === 7, 'Expected pending timer prefix');
$check(array_slice($events, 0, count($prefix)) === $prefix, 'Original history prefix changed');
$check(array_column($events, 'sequence') === range(1, count($events)), 'History sequence changed');
$counts = array_count_values(array_column($events, 'event_type'));
foreach (['VersionMarkerRecorded', 'ActivityScheduled', 'ActivityStarted', 'ActivityCompleted', 'TimerScheduled', 'TimerFired', 'WorkflowCompleted'] as $type) {
    $check(($counts[$type] ?? 0) === 1, 'Expected one '.$type);
}
$check(!isset($counts['CancellationRequested']) && !isset($counts['WorkflowCancelled']) && !isset($counts['WorkflowFailed']), 'Fault became business failure/cancellation');
$timersBefore = $select($before['workflow_run_timers']);
$timersAfter = $select($after['workflow_run_timers']);
$check(count($timersBefore) === 1 && count($timersAfter) === 1, 'Timer identity count changed');
foreach (['id', 'sequence', 'delay_seconds', 'fire_at', 'created_at'] as $key) {
    $check($timersBefore[0][$key] === $timersAfter[0][$key], 'Timer '.$key.' changed');
}
$check($timersBefore[0]['status'] === 'pending' && $timersAfter[0]['status'] === 'fired', 'Timer transition wrong');
$fired = array_values(array_filter($events, static fn (array $e): bool => $e['event_type'] === 'TimerFired'))[0];
$final = json_decode($finish['receipts'][array_key_last($finish['receipts'])]['body'], true, 512, JSON_THROW_ON_ERROR);
$expectedClock = (new DateTimeImmutable($fired['recorded_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
$check($final['output']['clock'] === $expectedClock, 'Replayed clock differs from recorded TimerFired');
$check($final['output']['activity'] === ['owner' => 'alpha', 'integer' => 9007199254740993, 'optional' => null, 'unicode' => 'activity λ'], 'Typed activity result changed');
$tasks = $select($after['workflow_tasks']);
$check(count(array_filter($tasks, static fn (array $t): bool => $t['task_type'] === 'activity')) === 1, 'Duplicate activity task');
foreach ($tasks as $task) { $check($task['status'] === 'completed', 'Unfinished task'); }
$check($after['jobs'] === [] && $after['failed_jobs'] === [], 'Queue has pending or failed jobs');
$check($before['workflow_commands'] === $after['workflow_commands'], 'Command identity changed');
echo json_encode(['pass' => true, 'profile' => $profile, 'original_run_id' => $run, 'preserved_prefix_events' => count($prefix), 'final_events' => count($events), 'timer_id' => $timersBefore[0]['id'], 'fire_at' => $timersBefore[0]['fire_at'], 'clock' => $expectedClock, 'typed_activity' => true, 'command_identity' => true, 'task_count' => count($tasks)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
