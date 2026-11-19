<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildDefinitionRegistry;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildScopeParticipation;
use Workflow\V2\StateMachine\Execution\ExecutionReference;

final class RetainedParticipationContractTest extends TestCase
{
    public static function invalidRegistries(): array
    {
        return [
            'missing type' => [[
                '' => [],
            ]],
            'non-map policies' => [[
                'example.child' => 'latest',
            ]],
            'unversioned policy' => [[
                'example.child' => [
                    'latest' => TestGreetingWorkflow::class,
                ],
            ]],
            'missing host definition' => [[
                'example.child' => [
                    str_repeat('a', 64) => '',
                ],
            ]],
            'untyped host definition' => [[
                'example.child' => [
                    str_repeat('a', 64) => [],
                ],
            ]],
        ];
    }

    #[DataProvider('invalidRegistries')]
    public function testInvalidTrustedConfigurationRefusesBeforeDefinitionAutoload(array $definitions): void
    {
        $autoloaded = [];
        $loader = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($loader);
        try {
            try {
                new ChildDefinitionRegistry($definitions);
                $this->fail('Invalid pinned child configuration must refuse.');
            } catch (InvalidArgumentException) {
                $this->assertSame([], $autoloaded);
            }
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public static function invalidScopePairs(): array
    {
        return [
            'same scope' => ['tenant-a', 'client-a', 'parent'],
            'foreign application' => ['tenant-b', 'client-a', 'child'],
            'foreign namespace' => ['tenant-a', 'client-b', 'child'],
        ];
    }

    #[DataProvider('invalidScopePairs')]
    public function testRetainedParticipationCannotCrossItsApplicationOrNativeNamespace(
        string $application,
        string $namespace,
        string $key,
    ): void {
        $parent = new ScopeIdentity('tenant-a', 'client-a', 'parent', 'generation-1');
        $child = new ScopeIdentity($application, $namespace, $key, 'generation-1');
        $this->expectExceptionMessage('A retained child needs another scope in the same application and namespace.');
        new ChildScopeParticipation($parent, $child, 'child');
    }

    public static function malformedParticipation(): array
    {
        return [
            'new schema' => ['version', 2],
            'untyped parent' => ['parent', 'parent'],
            'untyped child' => ['child', false],
            'untyped key' => ['obligationKey', []],
            'extra routing field' => ['latest', true],
        ];
    }

    #[DataProvider('malformedParticipation')]
    public function testStoredParticipationRefusesAmbiguousSchema(string $field, mixed $value): void
    {
        $payload = $this->participation()
            ->toArray();
        $payload[$field] = $value;
        $this->expectExceptionMessage('Unsupported retained child participation descriptor.');
        ChildScopeParticipation::fromArray($payload);
    }

    public static function invalidJointPolicies(): array
    {
        return [
            'retained without policy' => [true, null],
            'plain with retained policy' => [false, 'joint-v1'],
            'empty policy' => [true, ''],
            'unbounded policy' => [true, str_repeat('v', 129)],
        ];
    }

    #[DataProvider('invalidJointPolicies')]
    public function testParticipationAndItsOriginalPolicyAreAnIndivisibleDescriptor(
        bool $retained,
        ?string $policy,
    ): void {
        $identity = new BindingIdentity(
            new ExecutionReference('tenant-a', 'item-1', 'begin-1', str_repeat('a', 64), 'ready', '1'),
            'client-a',
            'parent-1',
            'parent-run-1',
            'child',
            'entry-1',
            'working',
            'example.child',
            str_repeat('b', 64),
            'delivered',
            str_repeat('c', 64),
        );
        $participation = $retained ? $this->participation() : null;
        $this->expectExceptionMessage('Retained participation requires its original joint authorization policy.');
        new ChildReservation(
            $identity,
            TestGreetingWorkflow::class,
            'sha256:' . str_repeat('d', 64),
            'binding-v1',
            $participation,
            $policy,
        );
    }

    private function participation(): ChildScopeParticipation
    {
        return new ChildScopeParticipation(
            new ScopeIdentity('tenant-a', 'client-a', 'parent', 'generation-1'),
            new ScopeIdentity('tenant-a', 'client-a', 'child', 'generation-1'),
            'child',
        );
    }
}
