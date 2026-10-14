<?php
declare(strict_types=1);
$db = new PDO('sqlite:/state/workflow.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->beginTransaction();
$result = [];
$tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'workflow_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $result[$table] = $db->query('SELECT * FROM "'.$table.'" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);
}
$result['jobs'] = $db->query('SELECT * FROM jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$result['failed_jobs'] = $db->query('SELECT * FROM failed_jobs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$db->commit();
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
