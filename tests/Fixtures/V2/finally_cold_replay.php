<?php

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

use Tests\Fixtures\V2\TestFinallyCleanupWorkflow;
use Workflow\V2\Support\WorkflowFiberRunner;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$database = json_decode((string) getenv('FINALLY_DB_CONFIG'), true, flags: JSON_THROW_ON_ERROR);
$dsn = match ($database['driver']) {
    'sqlite' => 'sqlite:' . $database['database'],
    'mysql', 'pgsql' => sprintf(
        '%s:host=%s;port=%s;dbname=%s',
        $database['driver'],
        $database['host'],
        $database['port'],
        $database['database'],
    ),
    default => throw new RuntimeException('Unsupported cold-replay database driver.'),
};
$pdo = new PDO($dsn, $database['username'] ?? '', $database['password'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$runId = (string) getenv('FINALLY_RUN_ID');
$statement = $pdo->prepare(
    'SELECT sequence, event_type, payload, recorded_at FROM workflow_history_events WHERE workflow_run_id = :run_id ORDER BY sequence'
);
$statement->execute([
    'run_id' => $runId,
]);
$history = [];
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $history[] = [
        'sequence' => (int) $row['sequence'],
        'event_type' => $row['event_type'],
        'payload' => json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR),
        'recorded_at' => $row['recorded_at'],
    ];
}
if ($history === []) {
    throw new RuntimeException('No persisted history found for finally replay.');
}

$result = WorkflowFiberRunner::forClass(
    TestFinallyCleanupWorkflow::class,
    'finally-cold-replay',
    $runId,
    [getenv('FINALLY_FAIL') === '1'],
    'avro',
    $history,
)->step();

echo json_encode([
    'completed' => $result->completed,
    'result' => $result->result,
    'history_events' => count($history),
], JSON_THROW_ON_ERROR);
