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
    'CodDescriptor',
    'CodReceipt',
    'CodSnapshot',
    'CodJournal',
    'CodRefundTerms',
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
    'CodRefundLedger',
    'Journal',
    'IncomingPaymentProvider',
    'CodPaymentProvider',
    'IncomingPaymentOperation',
    'RetainedOperation',
    'FinancialOperation',
    'ShipmentOperation',
] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
