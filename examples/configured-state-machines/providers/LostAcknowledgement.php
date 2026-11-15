<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use RuntimeException;

/** The provider transaction has committed; the caller did not receive its result. */
final class LostAcknowledgement extends RuntimeException
{
}
