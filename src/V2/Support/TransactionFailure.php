<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\DetectsLostConnections;
use Throwable;

/**
 * Classifies failures that end a database transaction rather than one
 * statement (#1231 review): a deadlock or lock wait (which InnoDB may roll
 * back entirely) and a lost connection.
 *
 * @internal
 */
final class TransactionFailure
{
    use DetectsConcurrencyErrors;
    use DetectsLostConnections;

    public static function endsTransaction(Throwable $throwable): bool
    {
        $detector = new self();

        for ($current = $throwable; $current !== null; $current = $current->getPrevious()) {
            if ($detector->causedByConcurrencyError($current) || $detector->causedByLostConnection($current)) {
                return true;
            }
        }

        return false;
    }
}
