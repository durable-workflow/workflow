<?php

declare(strict_types=1);

foreach ([
    'EffectLedger',
    'LedgerOperation',
    'OrderScopeAuthorization',
    'ShipmentWorkflow',
    'OrderWorkflow',
    'OrderCompensation',
] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
