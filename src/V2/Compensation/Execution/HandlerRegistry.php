<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Execution;

use Workflow\Exceptions\NonRetryableException;
use Workflow\V2\Compensation\Definition\Obligation;

/**
 * Configured by trusted host code. Stored descriptors select keys, never executable classes.
 *
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final readonly class HandlerRegistry
{
    /**
     * Handler types with this prefix are reserved for the engine.
     */
    public const RESERVED_PREFIX = 'dw.';

    /**
     * @param array<string, array<string, ScopedOperation>> $handlers
     */
    public function __construct(
        private array $handlers = []
    ) {
        foreach ($handlers as $type => $versions) {
            if (str_starts_with((string) $type, self::RESERVED_PREFIX)) {
                throw new \InvalidArgumentException('Handler types starting with "dw." are reserved for the engine.');
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
