<?php

declare(strict_types=1);

$profile = $argv[1];
$database = $argv[2] ?? '/state/workflow.sqlite';
$db = new PDO('sqlite:' . $database);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA query_only = ON');
$db->exec('PRAGMA busy_timeout = 5000');
$runs = $db->prepare('SELECT id, status FROM workflow_runs WHERE workflow_instance_id LIKE ?');
$runs->execute([$profile . '-%']);
$rows = $runs->fetchAll(PDO::FETCH_ASSOC);
$timers = $db->prepare('SELECT t.workflow_run_id, t.fire_at, e.recorded_at AS completed_at
    FROM workflow_run_timers t JOIN workflow_runs r ON r.id = t.workflow_run_id
    JOIN workflow_history_events e ON e.workflow_run_id = r.id AND e.event_type = ?
    WHERE r.workflow_instance_id LIKE ? AND t.status = ?');
$timers->execute(['WorkflowCompleted', $profile . '-%', 'fired']);
$latencies = [];
foreach ($timers->fetchAll(PDO::FETCH_ASSOC) as $timer) {
    $due = new DateTimeImmutable($timer['fire_at'], new DateTimeZone('UTC'));
    $completed = new DateTimeImmutable($timer['completed_at'], new DateTimeZone('UTC'));
    $latencies[] = ((float) $completed->format('U.u') - (float) $due->format('U.u')) * 1000;
}
$tasks = $db->prepare('SELECT t.status, COUNT(*) AS count FROM workflow_tasks t
    JOIN workflow_runs r ON r.id = t.workflow_run_id WHERE r.workflow_instance_id LIKE ? GROUP BY t.status');
$tasks->execute([$profile . '-%']);
echo json_encode([
    'profile' => $profile,
    'runs' => count($rows),
    'statuses' => array_count_values(array_column($rows, 'status')),
    'tasks' => $tasks->fetchAll(PDO::FETCH_ASSOC),
    'queue_jobs' => (int) $db->query('SELECT COUNT(*) FROM jobs')->fetchColumn(),
    'failed_jobs' => (int) $db->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn(),
    'timer_due_to_workflow_completion_ms' => $latencies,
], JSON_THROW_ON_ERROR), "\n";
