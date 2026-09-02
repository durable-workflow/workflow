<?php

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

use Workflow\Serializers\AvroBinaryValue;
use Workflow\Serializers\AvroMapValue;
use Workflow\Serializers\AvroValueJsonProjection;
use Workflow\V2\Support\MemoPayload;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$required = static function (string $name): string {
    $value = getenv($name);
    if (! is_string($value) || $value === '') {
        throw new RuntimeException(sprintf('Missing required cold-readback environment field [%s].', $name));
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
    default => throw new RuntimeException(sprintf('Unsupported cold-readback database driver [%s].', $driver)),
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
$runId = $required('MEMO_RUN_ID');

$memoStatement = $pdo->prepare('SELECT * FROM workflow_memos WHERE workflow_run_id = :run_id ORDER BY id');
$memoStatement->execute([
    'run_id' => $runId,
]);
$memos = [];
foreach ($memoStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $envelope = json_decode((string) $row['value'], true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($envelope)) {
        throw new RuntimeException('A persisted continued memo row did not contain an envelope.');
    }

    $memos[(string) $row['key']] = MemoPayload::decode($envelope);
}

if (! is_int($memos['long_value'] ?? null) || ! is_float($memos['double_value'] ?? null)) {
    throw new RuntimeException('The continued long and double memo branches did not survive cold readback.');
}
if (
    ! ($memos['binary_value'] ?? null) instanceof AvroBinaryValue
    || $memos['binary_value']->bytes !== "\x00\xFF"
    || ! ($memos['binary_text_value'] ?? null) instanceof AvroBinaryValue
    || $memos['binary_text_value']->bytes !== 'same-bytes'
    || ($memos['text_value'] ?? null) !== 'same-bytes'
    || ! ($memos['adapter_map'] ?? null) instanceof AvroMapValue
    || $memos['adapter_map']->pairs !== [['0', 'numeric-string-key'], ['word', 'ordinary-key']]
) {
    throw new RuntimeException('The continued binary, text, or adapted-map memo did not survive cold readback.');
}

$nested = $memos['nested'] ?? null;
$nestedSecond = is_array($nested) ? ($nested['second'] ?? null) : null;
if (
    ! is_array($nested)
    || ($nested['first'] ?? null) !== true
    || ! is_array($nestedSecond)
    || ($nestedSecond['left'] ?? null) !== 1
    || ! ($nestedSecond['right'] ?? null) instanceof AvroBinaryValue
    || $nestedSecond['right']->bytes !== 'nested-bytes'
) {
    throw new RuntimeException('The continued nested adapted-map memo did not survive cold readback.');
}

$expectedProjection = json_decode(
    json_encode(AvroValueJsonProjection::project($memos), JSON_THROW_ON_ERROR),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$expectedProjectionIdentity = MemoPayload::mapEnvelope($expectedProjection);
$eventStatement = $pdo->prepare(
    "SELECT event_type, payload FROM workflow_history_events
     WHERE workflow_run_id = :run_id AND event_type IN ('StartAccepted', 'WorkflowStarted')
     ORDER BY sequence",
);
$eventStatement->execute([
    'run_id' => $runId,
]);
$events = $eventStatement->fetchAll(PDO::FETCH_ASSOC);
if (count($events) !== 2) {
    throw new RuntimeException('The continued run start history was not found during cold readback.');
}

foreach ($events as $event) {
    $payload = json_decode((string) $event['payload'], true, flags: JSON_THROW_ON_ERROR);
    $memo = is_array($payload) ? ($payload['memo'] ?? null) : null;
    if (! is_array($payload) || ! is_array($memo) || MemoPayload::mapEnvelope($memo) !== $expectedProjectionIdentity) {
        throw new RuntimeException(sprintf(
            'The persisted %s memo history field is not the JSON-safe portable projection.',
            (string) $event['event_type'],
        ));
    }
}

echo json_encode([
    'history_projection_preserved' => true,
    'memo_readback_preserved' => true,
], JSON_THROW_ON_ERROR);
