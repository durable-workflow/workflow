<?php

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

use Tests\Fixtures\V2\TestAuthoredRetryReplayWorkflow;
use Workflow\V2\Exceptions\ActivityRetryPolicyMismatchException;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\WorkflowFiberRunner;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$database = json_decode((string) getenv('RETRY_REPLAY_DB_CONFIG'), true, flags: JSON_THROW_ON_ERROR);
$dsn = match ($database['driver']) {
    'sqlite' => 'sqlite:' . $database['database'],
    'mysql', 'pgsql' => sprintf(
        '%s:host=%s;port=%s;dbname=%s',
        $database['driver'],
        $database['host'],
        $database['port'],
        $database['database']
    ),
    default => throw new RuntimeException('Unsupported retry replay database driver.'),
};
$pdo = new PDO($dsn, $database['username'] ?? '', $database['password'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$runId = (string) getenv('RETRY_REPLAY_RUN_ID');
$statement = $pdo->prepare(
    'SELECT id, sequence, event_type, payload, recorded_at FROM workflow_history_events WHERE workflow_run_id = :run_id ORDER BY sequence'
);
$statement->execute([
    'run_id' => $runId,
]);
$history = [];
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $history[] = [
        'id' => $row['id'],
        'sequence' => (int) $row['sequence'],
        'event_type' => $row['event_type'],
        'payload' => json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR),
        'recorded_at' => $row['recorded_at'],
    ];
}
if ($history === []) {
    throw new RuntimeException('Missing original history for cold retry-policy replay.');
}
if (getenv('RETRY_REPLAY_CHANGED') === '1') {
    TestAuthoredRetryReplayWorkflow::$overrides = new ActivityOptions(
        startToCloseTimeout: 120,
        scheduleToCloseTimeout: 600,
        maxAttempts: 1,
        backoff: [1, 2, 5, 10, 15, 30, 60, 121]
    );
}
try {
    $step = WorkflowFiberRunner::forClass(
        TestAuthoredRetryReplayWorkflow::class,
        'retry-policy-cold-reader',
        $runId,
        [120, (string) getenv('RETRY_REPLAY_MODE')],
        'avro',
        $history
    )->step();
    echo json_encode([
        'rejected' => false,
        'completed' => $step->completed,
        'result' => $step->result,
    ], JSON_THROW_ON_ERROR);
} catch (ActivityRetryPolicyMismatchException $exception) {
    echo json_encode([
        'rejected' => true,
        'sequence' => $exception->workflowSequence,
        'field' => $exception->policyField,
    ], JSON_THROW_ON_ERROR);
}
