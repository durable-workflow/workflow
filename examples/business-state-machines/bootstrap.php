<?php

declare(strict_types=1);

foreach ([
    'SqlBusinessStore',
    'ProfileCatalogue',
    'ExampleOwnershipPolicy',
    'SqlEffectOperation',
    'BusinessCancellationRequested',
    'NestedCompensationExample',
    'NestedExampleActivity',
    'OrderWorkflow',
    'ShipmentWorkflow',
] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
