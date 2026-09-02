<?php

declare(strict_types=1);

namespace Workflow\V2\Exceptions;

use Error;

/**
 * Signals that a workflow run was cancelled.
 *
 * Extends {@see \Error} rather than {@see \Exception} so that a generic
 * ``catch (\Exception $e)`` block cannot accidentally swallow a cancellation
 * signal. Callers that want to treat cancellation distinctly from failure
 * must catch this class by name or catch {@see \Throwable}.
 *
 * PHP does not allow user classes to implement ``\Throwable`` directly, so
 * ``\Error`` is the idiomatic escape hatch for non-{@see \Exception}
 * throwables.
 */
final class WorkflowCancelledException extends Error
{
}
