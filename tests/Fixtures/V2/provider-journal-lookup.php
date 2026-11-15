<?php

declare(strict_types=1);

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/examples/configured-state-machines/providers/bootstrap.php';

$value = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
$owner = $value['identity'];
$request = new Request(
    new Identity($owner['client'], $owner['subject'], $owner['generation'], $owner['operation']),
    $value['action'],
    $value['terms'],
);
$journal = new Journal(new PDO('sqlite:' . $argv[1]), $owner['client']);
$observed = $journal->lookup($request);
echo json_encode([
    'status' => $observed->status->value,
    'effectId' => $observed->effectId,
    'admissionClosed' => $observed->admissionClosed,
], JSON_THROW_ON_ERROR);
