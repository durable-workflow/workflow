<?php

declare(strict_types=1);

namespace Workflow\V2\EntityReferences;

use RuntimeException;
use Workflow\Exceptions\NonRetryableExceptionContract;

/**
 * The worker refused a recorded reference before the activity ran (#996):
 * unknown type, unsupported version, or a resolved object that doesn't match
 * the declared parameter type. Always non-retryable.
 *
 * @experimental Draft API for workflow issue 996.
 */
final class EntityReferenceRefused extends RuntimeException implements NonRetryableExceptionContract
{
    public const INVALID_REFERENCE = 'invalid_entity_reference';

    public const INVALID_REGISTRATION = 'invalid_entity_reference_registration';

    public const AMBIGUOUS_TYPE = 'ambiguous_entity_reference_type';

    public const UNSUPPORTED_EXECUTION_MODE = 'entity_reference_execution_mode_not_supported';

    public const UNKNOWN_TYPE = 'unknown_entity_reference_type';

    public const UNSUPPORTED_VERSION = 'unsupported_entity_reference_version';

    public const PARAMETER_MISMATCH = 'entity_reference_parameter_mismatch';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
