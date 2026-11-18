<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use RuntimeException;

/**
 * The original parcel's confirmed carrier incident ends the delivery wait; the Order settles the selected remedy.
 */
final class TransitIncidentObserved extends RuntimeException
{
}
