<?php

declare(strict_types=1);

require_once __DIR__ . '/../configured-state-machines/providers/bootstrap.php';
require_once __DIR__ . '/../configured-state-machines/BooleanFact.php';
require_once __DIR__ . '/../business-state-machines/SqlBusinessStore.php';
require_once __DIR__ . '/../business-state-machines/BusinessCancellationRequested.php';
require_once __DIR__ . '/Catalogue.php';
require_once __DIR__ . '/FinancialCatalogue.php';
require_once __DIR__ . '/PhysicalCatalogue.php';
require_once __DIR__ . '/FundingCatalogue.php';
require_once __DIR__ . '/CodFundingPolicy.php';
require_once __DIR__ . '/AdvanceBalancePolicy.php';
require_once __DIR__ . '/OrderTerms.php';
require_once __DIR__ . '/BillingPeriod.php';
require_once __DIR__ . '/BillingPeriodPolicy.php';
foreach (['BillingPeriodAssignments', 'BillingPeriodStore', 'BillingPeriodAssessment', 'BillingPeriodOwner',
    'BillingPeriodBoundaryActivity', 'BillingPeriodSnapshotActivity', 'BillingPeriodAdmissionActivity',
    'BillingPeriodCommitActivity', 'BillingPeriodStep', 'ServicePeriodPayment'] as $billingClass) {
    require_once __DIR__ . '/' . $billingClass . '.php';
}
require_once __DIR__ . '/CartPlan.php';
require_once __DIR__ . '/CartLeaseOperation.php';
require_once __DIR__ . '/BindingPlan.php';
foreach ([
    'CartPolicy', 'OrderPeriodPolicy', 'OrderPeriodPaymentReceipt', 'OrderBillingPeriodStore',
    'RootPolicy', 'RootCancellationStore', 'UnitMoneyPolicy', 'StockAllocation', 'StockWarehouse', 'StockPolicy', 'TransitPolicy',
    'Authority', 'OwnerOperation', 'CartOrderReceipt', 'CartStore', 'MachineStore', 'AssessmentStore', 'CancellationStore', 'CancellationFinalizer',
    'FinancialInspectionStore', 'FinancialInspectionActivity', 'FinancialAuthority',
    'IncomingPaymentInspectionStore', 'IncomingPaymentInspectionActivity',
    'CodInspectionStore', 'FundingPaymentExecution', 'CodFundingPayment', 'AdvanceBalancePayment',
    'OriginalStock', 'FinancialHandoffStore', 'FinancialHandoffActivity', 'FinancialStep', 'FinancialPreparationStep',
    'FundingAuthority', 'FundingFactsStore', 'FundingHandoffStore', 'FundingHandoffActivity',
    'FundingStep', 'FundingPayment',
    'StockOperation', 'StockSelectionStore', 'StockSelectionWait', 'BoundaryActivity', 'SnapshotActivity', 'AdmissionActivity', 'CommitActivity',
    'RetainedStep', 'Machine', 'Order', 'CheckoutOrder', 'Payment', 'Booking', 'Cancellation',
    'AuthorizedPayment', 'AuthorizationCheckout', 'FundingCheckout', 'CartBoundaryActivity', 'CartPreparationStep', 'CartCheckout',
    'ShipmentAuthority', 'ShipmentBoundaryStore', 'ShipmentBoundaryActivity', 'ShipmentStep', 'FullShipment', 'FulfillmentStep',
    'PostDeliveryRecords', 'ReturnStore', 'ReturnBoundaryActivity', 'ReturnWait', 'UnitReturn',
    'ExchangeStore', 'ExchangeBoundaryActivity', 'ExchangeWait', 'UnitExchange',
    'TransitIncidentObserved', 'TransitRemedyStep', 'IncidentStore', 'IncidentBoundaryActivity', 'IncidentWait',
    'TransitIncident',
] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
