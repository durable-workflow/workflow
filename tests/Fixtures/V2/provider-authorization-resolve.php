<?php

declare(strict_types=1);

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Status;

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/examples/configured-state-machines/providers/bootstrap.php';

$request = Request::fromArray(json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR));
$time = (int) $argv[4];
$provider = new Journal(new PDO('sqlite:' . $argv[1]), $request->identity->client, static fn (): int => $time);
$result = $provider->resolve($request, Status::from($argv[3]));
echo json_encode([
    'status' => $result->status->value,
    'admissionClosed' => $result->admissionClosed,
], JSON_THROW_ON_ERROR);
