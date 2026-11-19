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
     * @param array<string, array<string, ScopedOperation>> $handlers
     */
    public function __construct(
        private array $handlers = []
    ) {
        if (isset($handlers[\Workflow\V2\Compensation\Persistence\ChildScopes::TYPE])
            || isset($handlers[\Workflow\V2\Compensation\Persistence\ChildScopes::RESERVED_TYPE])) {
            throw new \InvalidArgumentException('The child-scope handler key is reserved.');
        }
        foreach ($handlers as $versions) {
            foreach ($versions as $handler) {
                if (! $handler instanceof ScopedOperation) {
                    throw new \InvalidArgumentException('Scope handlers must implement ScopedOperation.');
                }
            }
        }
    }

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
