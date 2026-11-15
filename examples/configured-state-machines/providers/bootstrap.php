<?php

declare(strict_types=1);

foreach (['Identity', 'Request', 'Status', 'Observation', 'LostAcknowledgement', 'Journal'] as $class) {
    require_once __DIR__ . '/' . $class . '.php';
}
