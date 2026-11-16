<?php

declare(strict_types=1);

foreach ([
    'Identity',
    'AuthorizationTerms',
    'ShipmentTerms',
    'Request',
    'FinancialDescriptor',
    'ShipmentDescriptor',
    'IncomingPaymentDescriptor',
    'IncomingPaymentReceipt',
    'IncomingRefundTerms',
    'Status',
    'Observation',
    'FinancialSnapshot',
    'ShipmentSnapshot',
    'LostAcknowledgement',
    'AuthorizationLedger',
    'ShipmentLedger',
    'ShipmentJournal',
    'IncomingPaymentJournal',
    'IncomingRefundLedger',
    'Journal',
    'IncomingPaymentOperation',
    'RetainedOperation',
    'FinancialOperation',
    'ShipmentOperation',
] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
