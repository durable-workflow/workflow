<?php

declare(strict_types=1);

// Original SQL credentials/configuration arrive only over the private parent pipe.
if (PHP_SAPI !== 'cli' || ! isset($argv[1]) || ! is_file($argv[1])) {
    exit(1);
}
require_once $argv[1];
Workflow\V2\Support\EmbeddedActivityLeaseMaintainer::run();
