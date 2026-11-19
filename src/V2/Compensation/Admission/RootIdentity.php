<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Admission;

use InvalidArgumentException;
use Workflow\Serializers\CodecRegistry;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\StartOptions;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\Support\RoutingResolver;
use Workflow\V2\Support\SourceFingerprint;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Workflow;
use Workflow\WorkflowMetadata;

/**
 * @experimental One original root scope/generation and its complete native intent.
 * A descriptor is neither authorization nor proof that a run or scope opened.
 */
final readonly class RootIdentity
{
    public function __construct(
        public ScopeIdentity $scope,
        public string $workflowClass,
        public string $workflowType,
        public string $definitionFingerprint,
        public string $inputFingerprint,
        public RootStartProfile $profile,
    ) {
        foreach ([$workflowClass, $workflowType] as $name) {
            if (trim($name) === '' || strlen($name) > 512) {
                throw new InvalidArgumentException('Root definitions require bounded class and type names.');
            }
        }
        if (preg_match('/\Asha256:[0-9a-f]{64}\z/D', $definitionFingerprint) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $inputFingerprint) !== 1) {
            throw new InvalidArgumentException('Root definitions and inputs require immutable fingerprints.');
        }
        PortableDescriptor::encode($this->toArray());
    }

    /**
     * Trusted application authoring only. Stored descriptors use fromArray(),
     * which deliberately never resolves a historical class or deployed alias.
     *
     * @param class-string<Workflow> $workflow
     * @param array<int, mixed> $arguments Bounded portable values, as for reserved children.
     */
    public static function forWorkflow(
        ScopeIdentity $scope,
        string $workflow,
        array $arguments,
        ?StartOptions $options = null,
    ): self {
        if (! is_a($workflow, Workflow::class, true)) {
            throw new InvalidArgumentException('Root admission requires a native V2 workflow.');
        }
        // Root descriptors are immutable and byte-compared, so they keep the
        // version 1 definition fingerprint they have always recorded.
        $fingerprint = WorkflowDefinition::fingerprint($workflow, SourceFingerprint::V1);
        $metadata = new WorkflowMetadata($arguments);
        $connection = RoutingResolver::workflowConnection($workflow, $metadata) ?? config('queue.default');
        $queue = RoutingResolver::workflowQueue($workflow, $metadata);
        if ($fingerprint === null || ! is_string($connection) || ! is_string($queue)) {
            throw new InvalidArgumentException('The root definition and native routing must be explicit.');
        }
        return new self(
            $scope,
            $workflow,
            TypeRegistry::for($workflow),
            $fingerprint,
            ChildReservation::inputFingerprint($arguments),
            new RootStartProfile(
                $connection,
                $queue,
                CodecRegistry::defaultCodec(),
                $options ?? new StartOptions(),
                \Workflow\V2\Support\WorkerCompatibility::current()
            ),
        );
    }

    public function instanceId(): string
    {
        return 'dw-bound-root-' . $this->scope->id();
    }

    public function fingerprint(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'scope' => $this->scope->toArray(),
            'workflowClass' => $this->workflowClass,
            'workflowType' => $this->workflowType,
            'definitionFingerprint' => $this->definitionFingerprint,
            'inputFingerprint' => $this->inputFingerprint,
            'profile' => $this->profile->toArray(),
        ];
    }

    public static function fromArray(array $value): self
    {
        RootFormat::keys($value, ['version', 'scope', 'workflowClass', 'workflowType', 'definitionFingerprint',
            'inputFingerprint', 'profile']);
        RootFormat::strings($value, ['workflowClass', 'workflowType', 'definitionFingerprint', 'inputFingerprint']);
        if ($value['version'] !== 1 || ! is_array($value['scope']) || ! is_array($value['profile'])) {
            throw new InvalidArgumentException('Unsupported root identity.');
        }
        return new self(
            ScopeIdentity::fromArray($value['scope']),
            $value['workflowClass'],
            $value['workflowType'],
            $value['definitionFingerprint'],
            $value['inputFingerprint'],
            RootStartProfile::fromArray($value['profile']),
        );
    }
}
