<?php

declare(strict_types=1);

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

use Workflow\V2\Models\WorkflowSchedule;
use Workflow\V2\Support\UtcScheduleTimestamp;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$required = static function (string $name): string {
    $value = getenv($name);
    if (! is_string($value) || $value === '') {
        throw new RuntimeException(sprintf('Missing schedule cold-readback field [%s].', $name));
    }

    return $value;
};

$driver = $required('SCHEDULE_DB_DRIVER');
$database = $required('SCHEDULE_DB_DATABASE');
$dsn = match ($driver) {
    'sqlite' => 'sqlite:' . $database,
    'pgsql', 'mysql' => sprintf(
        '%s:host=%s;port=%s;dbname=%s',
        $driver,
        $required('SCHEDULE_DB_HOST'),
        $required('SCHEDULE_DB_PORT'),
        $database,
    ),
    default => throw new RuntimeException(sprintf('Unsupported schedule database driver [%s].', $driver)),
};

$pdo = new PDO(
    $dsn,
    (string) getenv('SCHEDULE_DB_USERNAME'),
    (string) getenv('SCHEDULE_DB_PASSWORD'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ],
);
if ($driver === 'mysql') {
    $pdo->exec("SET time_zone = '+00:00'");
}

$scheduleId = $required('SCHEDULE_ID');
$rawQuery = $pdo->prepare('SELECT next_fire_at FROM workflow_schedules WHERE id = :id');
$rawQuery->execute([
    'id' => $scheduleId,
]);
$raw = $rawQuery->fetchColumn();
if (! is_string($raw)) {
    throw new RuntimeException('The persisted schedule timestamp was not found.');
}

$instant = (new UtcScheduleTimestamp())->get(new WorkflowSchedule(), 'next_fire_at', $raw, []);
if ($instant === null) {
    throw new RuntimeException('The persisted schedule timestamp was null.');
}

$dueQuery = $pdo->prepare('SELECT COUNT(*) FROM workflow_schedules WHERE id = :id AND next_fire_at <= :due_at');
$dueQuery->execute([
    'id' => $scheduleId,
    'due_at' => $required('SCHEDULE_DUE_AT'),
]);

echo json_encode([
    'timestamp' => $instant->getTimestamp(),
    'due_count' => (int) $dueQuery->fetchColumn(),
], JSON_THROW_ON_ERROR);
