<?php

declare(strict_types=1);

foreach ([
    'Identity',
    'AuthorizationTerms',
    'Request',
    'Status',
    'Observation',
    'LostAcknowledgement',
    'AuthorizationLedger',
    'Journal',
    'RetainedOperation',
    'FinancialOperation',
] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
