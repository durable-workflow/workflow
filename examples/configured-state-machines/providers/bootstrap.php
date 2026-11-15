<?php

declare(strict_types=1);

foreach ([
    'Identity',
    'Request',
    'Status',
    'Observation',
    'LostAcknowledgement',
    'Journal',
    'RetainedOperation',
] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
