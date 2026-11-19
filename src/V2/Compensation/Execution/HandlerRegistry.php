<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Execution;

use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Persistence\ChildScopes;

/**
 * Configured by trusted host code. Stored descriptors select keys, never executable classes.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final readonly class HandlerRegistry
{
    /**
     * Handler types with this prefix are reserved for the engine. Workflows that record the
     * `dw.compensation.registration-checks` patch cannot register obligations with them.
     */
    public const RESERVED_PREFIX = 'dw.';

    /**
     * A registry may still map a `dw.` type that a history recorded before the patch registered, so those
     * obligations stay compensable. The engine's own handler types cannot be mapped.
     *
     * @param array<string, array<string, ScopedOperation>> $handlers
     */
    public function __construct(
        private array $handlers = []
    ) {
        foreach ($handlers as $type => $versions) {
            if (in_array((string) $type, [ChildScopes::TYPE, ChildScopes::RESERVED_TYPE], true)) {
                throw new \InvalidArgumentException('The engine handler type "' . $type . '" cannot be mapped.');
            }
            if (! is_array($versions)) {
                throw new \InvalidArgumentException('Scope handlers are registered as [type => [version => handler]].');
            }
            foreach ($versions as $handler) {
                if (! $handler instanceof ScopedOperation) {
                    throw new \InvalidArgumentException('Scope handlers must implement ScopedOperation.');
                }
            }
        }
    }

    /**
     * @internal The engine resolves the pinned handler and validates input before any provider call.
     */
    public function resolve(Obligation $obligation): ScopedOperation
    {
        $handler = $this->handlers[$obligation->handlerType][$obligation->handlerVersion] ?? null;
        if (! $handler instanceof ScopedOperation) {
            throw new NonRetryableException('scope_handler_unavailable');
        }
        try {
            $handler->assertInput($obligation->input);
        } catch (\Throwable) {
            throw new NonRetryableException('scope_input_incompatible');
        }
        return $handler;
    }
}
