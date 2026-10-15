<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;

/** @internal */
final class TraceLinkState
{
    private array $seen = [];

    private int $dropped = 0;

    public function __construct(
        private readonly SpanInterface $span
    ) {
    }

    public function isRecording(): bool
    {
        return $this->span->isRecording();
    }

    /**
     * @param array<string, string> $attributes
     */
    public function add(SpanContextInterface $context, array $attributes): void
    {
        $identity = $context->getTraceId() . ':' . $context->getSpanId();
        if (isset($this->seen[$identity])) {
            return;
        }
        if (count($this->seen) >= 32) {
            $this->span->setAttribute('durable.links.dropped', ++$this->dropped);

            return;
        }
        $this->span->addLink($context, $attributes);
        $this->seen[$identity] = true;
    }
}
