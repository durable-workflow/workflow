<?php

declare(strict_types=1);

$profile = $argv[1];
$database = $argv[2] ?? '/state/workflow.sqlite';
$db = new PDO('sqlite:' . $database);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA query_only = ON');
$db->exec('PRAGMA busy_timeout = 5000');
$queueDatabase = $argv[3] ?? $database;
$queue = $queueDatabase === $database ? $db : new PDO('sqlite:' . $queueDatabase);
$queue->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$queue->exec('PRAGMA query_only = ON');
$queue->exec('PRAGMA busy_timeout = 5000');
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
$repairs = $db->prepare('SELECT COALESCE(SUM(t.repair_count), 0) FROM workflow_tasks t
    JOIN workflow_runs r ON r.id = t.workflow_run_id WHERE r.workflow_instance_id LIKE ?');
$repairs->execute([$profile . '-%']);
$taskTypes = $db->prepare('SELECT t.task_type, COUNT(*) AS count,
    COALESCE(SUM(t.repair_count), 0) AS repair_count, MAX(t.attempt_count) AS max_attempt_count
    FROM workflow_tasks t JOIN workflow_runs r ON r.id = t.workflow_run_id
    WHERE r.workflow_instance_id LIKE ? GROUP BY t.task_type ORDER BY t.task_type');
$taskTypes->execute([$profile . '-%']);
$events = $db->prepare('SELECT e.event_type, COUNT(*) AS count FROM workflow_history_events e
    JOIN workflow_runs r ON r.id = e.workflow_run_id
    WHERE r.workflow_instance_id LIKE ? GROUP BY e.event_type ORDER BY e.event_type');
$events->execute([$profile . '-%']);
echo json_encode([
    'profile' => $profile,
    'runs' => count($rows),
    'statuses' => array_count_values(array_column($rows, 'status')),
    'tasks' => $tasks->fetchAll(PDO::FETCH_ASSOC),
    'repair_count' => (int) $repairs->fetchColumn(),
    'task_types' => $taskTypes->fetchAll(PDO::FETCH_ASSOC),
    'event_counts' => $events->fetchAll(PDO::FETCH_ASSOC),
    'queue_jobs' => (int) $queue->query('SELECT COUNT(*) FROM jobs')->fetchColumn(),
    'failed_jobs' => (int) $queue->query('SELECT COUNT(*) FROM failed_jobs')->fetchColumn(),
    'timer_due_to_workflow_completion_ms' => $latencies,
], JSON_THROW_ON_ERROR), "\n";
