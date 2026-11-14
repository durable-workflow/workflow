<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\BusinessStateMachines;

/** The recorded SQL service confirmed that business forward admission is closed. */
final class BusinessCancellationRequested extends \RuntimeException
{
}
