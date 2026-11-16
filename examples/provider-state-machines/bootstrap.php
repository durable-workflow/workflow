<?php

declare(strict_types=1);

require_once __DIR__ . '/../configured-state-machines/providers/bootstrap.php';
require_once __DIR__ . '/../configured-state-machines/BooleanFact.php';
require_once __DIR__ . '/../business-state-machines/SqlBusinessStore.php';
require_once __DIR__ . '/../business-state-machines/BusinessCancellationRequested.php';
require_once __DIR__ . '/Catalogue.php';
require_once __DIR__ . '/FinancialCatalogue.php';
require_once __DIR__ . '/PhysicalCatalogue.php';
require_once __DIR__ . '/OrderTerms.php';
require_once __DIR__ . '/BindingPlan.php';
foreach ([
    'Authority', 'OwnerOperation', 'MachineStore', 'AssessmentStore', 'CancellationStore',
    'FinancialInspectionStore', 'FinancialInspectionActivity', 'FinancialAuthority',
    'IncomingPaymentInspectionStore', 'IncomingPaymentInspectionActivity',
    'FinancialHandoffStore', 'FinancialHandoffActivity', 'FinancialStep', 'FinancialPreparationStep',
    'BoundaryActivity', 'SnapshotActivity', 'AdmissionActivity', 'CommitActivity',
    'RetainedStep', 'Machine', 'Order', 'CheckoutOrder', 'Payment', 'Booking', 'Cancellation',
    'AuthorizedPayment', 'AuthorizationCheckout',
    'ShipmentAuthority', 'ShipmentBoundaryStore', 'ShipmentBoundaryActivity', 'ShipmentStep', 'FullShipment', 'FulfillmentStep',
] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
