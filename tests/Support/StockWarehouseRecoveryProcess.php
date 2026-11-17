<?php

declare(strict_types=1);

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ProviderStateMachines\StockWarehouse;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/examples/provider-state-machines/bootstrap.php';
require_once dirname(__DIR__, 2) . '/examples/provider-state-machines/StockAllocation.php';
require_once dirname(__DIR__, 2) . '/examples/provider-state-machines/StockWarehouse.php';

$path = $_SERVER['argv'][1] ?? null;
if (! is_string($path) || ! is_file($path)) {
    throw new LogicException('The original private warehouse database is unavailable.');
}
$db = new PDO('sqlite:' . $path);
$warehouse = new StockWarehouse($db);
$rows = $db->query('SELECT release_descriptor FROM allocation_reservations WHERE closed = 1 LIMIT 2')
    ->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 1) {
    throw new LogicException('Recovery requires exactly one original committed release.');
}
$release = Request::fromArray(json_decode($rows[0]['release_descriptor'], true, 32, JSON_THROW_ON_ERROR));
echo json_encode([
    'pid' => getmypid(),
    'result' => $warehouse->release($release),
    'stock' => $warehouse->inventory($release->identity->client, $release->terms['sku']),
], JSON_THROW_ON_ERROR), PHP_EOL;
