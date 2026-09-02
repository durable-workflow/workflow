<?php

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

use Tests\Fixtures\V2\TestPortableMemoBinaryContentDriftWorkflow;
use Tests\Fixtures\V2\TestPortableMemoDoubleDriftWorkflow;
use Tests\Fixtures\V2\TestPortableMemoTextDriftWorkflow;
use Tests\Fixtures\V2\TestPortableMemoWorkflow;
use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\V2\Exceptions\HistoryEventShapeMismatchException;
use Workflow\V2\Support\MemoPayload;
use Workflow\V2\Support\WorkflowFiberRunner;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$required = static function (string $name): string {
    $value = getenv($name);
    if (! is_string($value) || $value === '') {
        throw new RuntimeException(sprintf('Missing required cold-replay environment field [%s].', $name));
    }

    return $value;
};

$driver = $required('MEMO_DB_DRIVER');
$database = $required('MEMO_DB_DATABASE');
$dsn = match ($driver) {
    'sqlite' => 'sqlite:' . $database,
    'pgsql' => sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $required('MEMO_DB_HOST'),
        $required('MEMO_DB_PORT'),
        $database,
    ),
    'mysql' => sprintf(
        'mysql:host=%s;port=%s;dbname=%s',
        $required('MEMO_DB_HOST'),
        $required('MEMO_DB_PORT'),
        $database,
    ),
    default => throw new RuntimeException(sprintf('Unsupported cold-replay database driver [%s].', $driver)),
};
$user = getenv('MEMO_DB_USERNAME');
$password = getenv('MEMO_DB_PASSWORD');
$pdo = new PDO(
    $dsn,
    is_string($user) ? $user : '',
    is_string($password) ? $password : '',
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ],
);

$eventId = $required('MEMO_EVENT_ID');
$runId = $required('MEMO_RUN_ID');
$eventStatement = $pdo->prepare(
    'SELECT sequence, event_type, payload, recorded_at FROM workflow_history_events WHERE id = :id',
);
$eventStatement->execute([
    'id' => $eventId,
]);
$event = $eventStatement->fetch(PDO::FETCH_ASSOC);
if (! is_array($event)) {
    throw new RuntimeException('The persisted memo history event was not found during cold replay.');
}

$payload = json_decode((string) $event['payload'], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($payload)) {
    throw new RuntimeException('The persisted memo history payload did not decode to an object.');
}

$memoStatement = $pdo->prepare('SELECT * FROM workflow_memos WHERE workflow_run_id = :run_id ORDER BY id');
$memoStatement->execute([
    'run_id' => $runId,
]);
$memos = [];
foreach ($memoStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $envelope = json_decode((string) $row['value'], true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($envelope)) {
        throw new RuntimeException('A persisted memo projection row did not contain an envelope.');
    }

    $memos[(string) $row['key']] = MemoPayload::decode($envelope);
}

if (! is_int($memos['long_value'] ?? null) || ! is_float($memos['double_value'] ?? null)) {
    throw new RuntimeException('The persisted long and double memo branches did not survive cold readback.');
}
if (
    ! ($memos['binary_value'] ?? null) instanceof AvroBinaryValue
    || $memos['binary_value']->bytes !== "\x00\xFF"
    || ! ($memos['binary_text_value'] ?? null) instanceof AvroBinaryValue
    || $memos['binary_text_value']->bytes !== 'same-bytes'
    || $memos['text_value'] !== 'same-bytes'
) {
    throw new RuntimeException('The persisted binary and text memo branches did not survive cold readback.');
}
if (! ($memos['adapter_map'] ?? null) instanceof AvroMapValue) {
    throw new RuntimeException('The persisted Avro map adapter did not survive cold readback.');
}

$history = [[
    'sequence' => (int) $event['sequence'],
    'event_type' => (string) $event['event_type'],
    'payload' => $payload,
    'recorded_at' => (string) $event['recorded_at'],
]];
$replayed = WorkflowFiberRunner::forClass(
    TestPortableMemoWorkflow::class,
    'portable-memo-workflow',
    $runId,
    [],
    'avro',
    $history,
)->step();

if (! $replayed->completed || ($replayed->command['type'] ?? null) !== 'complete_workflow') {
    throw new RuntimeException('The persisted equivalent memo envelope did not match during cold replay.');
}

$rejectedDrifts = [];
foreach ([
    TestPortableMemoDoubleDriftWorkflow::class,
    TestPortableMemoBinaryContentDriftWorkflow::class,
    TestPortableMemoTextDriftWorkflow::class,
] as $workflowClass) {
    try {
        WorkflowFiberRunner::forClass(
            $workflowClass,
            'portable-memo-workflow',
            $runId,
            [],
            'avro',
            $history,
        )->step();
    } catch (HistoryEventShapeMismatchException) {
        $rejectedDrifts[] = $workflowClass;
    }
}

echo json_encode([
    'matching_replay_completed' => true,
    'memo_readback_preserved' => true,
    'rejected_drift_count' => count($rejectedDrifts),
], JSON_THROW_ON_ERROR);
