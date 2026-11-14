<?php

declare(strict_types=1);

require_once __DIR__ . '/../business-state-machines/bootstrap.php';
foreach (['BooleanFact', 'Catalogue', 'Ownership', 'OwnerOperation', 'MachineStore', 'SnapshotActivity',
    'AdmissionActivity', 'CommitActivity', 'BoundaryActivity', 'RetainedStep', 'MachineExecution', 'Order', 'Shipment', 'Cancellation'] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
