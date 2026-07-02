<?php

declare(strict_types=1);

namespace Workflow\V2\Conformance;

use RuntimeException;
use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\signal;
use Workflow\V2\Workflow;

#[Type(self::TYPE_KEY)]
#[Signal('advance')]
final class WorkflowUpdatesConformanceWorkflow extends Workflow
{
    public const TYPE_KEY = 'workflow-v2-update-conformance';

    /**
     * @var list<array<string, mixed>>
     */
    private array $events = [];

    /**
     * @var array<string, mixed>
     */
    private array $lastPayload = [];

    /**
     * @return array<string, mixed>
     */
    public function handle(mixed $mode = null): array
    {
        if ($mode === 'complete' || (is_array($mode) && ($mode['mode'] ?? null) === 'complete')) {
            return [
                'status' => 'completed-immediately',
                'events' => $this->events,
            ];
        }

        while (true) {
            signal('advance');
        }
    }

    /**
     * @return array<string, mixed>
     */
    #[QueryMethod]
    public function state(): array
    {
        return [
            'events' => $this->events,
            'last_payload' => $this->lastPayload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[UpdateMethod]
    public function approve(bool $approved = true, string $label = 'accepted'): array
    {
        if (! $approved) {
            throw new RuntimeException('approval refused by PHP update handler');
        }

        $this->events[] = [
            'kind' => 'approve',
            'label' => $label,
        ];

        return [
            'approved' => true,
            'label' => $label,
            'count' => count($this->events),
            'runtime' => 'workflow-php',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    #[UpdateMethod('adjust_payload')]
    public function adjustPayload(array $payload): array
    {
        $this->lastPayload = $payload;
        $this->events[] = [
            'kind' => 'payload',
            'payload' => $payload,
        ];

        return [
            'received' => $payload,
            'count' => count($this->events),
            'runtime' => 'workflow-php',
        ];
    }

    #[UpdateMethod('fail_update')]
    public function failUpdate(string $message = 'workflow update failed by PHP handler'): never
    {
        throw new RuntimeException($message);
    }
}
