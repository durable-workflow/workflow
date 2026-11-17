<?php

declare(strict_types=1);

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundCommand;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\UnitRefundGateway;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/examples/configured-state-machines/providers/bootstrap.php';

$path = $_SERVER['argv'][1] ?? null;
$id = $_SERVER['argv'][2] ?? null;
if (! is_string($path) || ! is_file($path) || ! is_string($id) || preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1) {
    throw new LogicException('Recovery requires the original provider database and fixed business command.');
}
$db = new PDO('sqlite:' . $path);
$query = $db->prepare('SELECT descriptor FROM unit_refund_commands WHERE id = ?');
$query->execute([$id]);
$descriptor = $query->fetchColumn();
if (! is_string($descriptor)) {
    throw new LogicException('The original refund command is unavailable.');
}
$command = UnitRefundCommand::fromArray(json_decode($descriptor, true, 32, JSON_THROW_ON_ERROR));
$gateway = new UnitRefundGateway($db, 'shop-a');
echo json_encode([
    'pid' => getmypid(),
    'observation' => $gateway->lookup($command),
    'replayed' => $gateway->attempt($command),
    'payouts' => (int) $db->query('SELECT COUNT(*) FROM unit_refund_payouts')
        ->fetchColumn(),
], JSON_THROW_ON_ERROR), PHP_EOL;
