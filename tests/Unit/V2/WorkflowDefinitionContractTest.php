<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use LogicException;
use stdClass;
use Tests\Fixtures\V2\TestCommandTargetWorkflow;
use Tests\NonDatabaseTestCase;
use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\Workflow;

final class WorkflowDefinitionContractTest extends NonDatabaseTestCase
{
    public function testItPublishesAndResolvesTheCompleteDurableCommandContract(): void
    {
        $contract = WorkflowDefinition::commandContract(TestCommandTargetWorkflow::class);

        $this->assertSame(['approval-stage', 'approvalMatches'], $contract['queries']);
        $this->assertSame(['approved-by', 'rejected-by'], $contract['signals']);
        $this->assertSame(['mark-approved'], $contract['updates']);
        $this->assertSame('handle', $contract['entry_method']);
        $this->assertSame('canonical', $contract['entry_mode']);
        $this->assertSame(TestCommandTargetWorkflow::class, $contract['entry_declaring_class']);

        $approvalQuery = [
            'name' => 'approval-stage',
            'parameters' => [],
        ];
        $matchingQuery = [
            'name' => 'approvalMatches',
            'parameters' => [[
                'name' => 'stage',
                'position' => 0,
                'required' => true,
                'variadic' => false,
                'default_available' => false,
                'default' => null,
                'type' => 'string',
                'allows_null' => false,
            ]],
        ];

        $this->assertSame([$approvalQuery, $matchingQuery], $contract['query_contracts']);
        $this->assertSame($approvalQuery, WorkflowDefinition::queryContract(
            TestCommandTargetWorkflow::class,
            'approval-stage',
        ));
        $this->assertSame($approvalQuery, WorkflowDefinition::queryContract(
            TestCommandTargetWorkflow::class,
            'approvalStage',
        ));
        $this->assertSame([
            'name' => 'approval-stage',
            'method' => 'approvalStage',
        ], WorkflowDefinition::resolveQueryTarget(TestCommandTargetWorkflow::class, 'approval-stage'));
        $this->assertSame([
            'name' => 'approval-stage',
            'method' => 'approvalStage',
        ], WorkflowDefinition::resolveQueryTarget(TestCommandTargetWorkflow::class, 'approvalStage'));
        $this->assertNull(WorkflowDefinition::queryContract(TestCommandTargetWorkflow::class, 'missing'));
        $this->assertTrue(WorkflowDefinition::hasQueryMethod(TestCommandTargetWorkflow::class, 'approvalStage'));
        $this->assertFalse(WorkflowDefinition::hasQueryMethod(TestCommandTargetWorkflow::class, 'missing'));

        $signalContract = [
            'name' => 'approved-by',
            'parameters' => [[
                'name' => 'actor',
                'position' => 0,
                'required' => true,
                'variadic' => false,
                'default_available' => false,
                'default' => null,
                'type' => 'string',
                'allows_null' => true,
            ]],
        ];

        $this->assertSame([$signalContract], $contract['signal_contracts']);
        $this->assertSame(
            $signalContract,
            WorkflowDefinition::signalContract(TestCommandTargetWorkflow::class, 'approved-by'),
        );
        $this->assertNull(WorkflowDefinition::signalContract(TestCommandTargetWorkflow::class, 'missing'));
        $this->assertTrue(WorkflowDefinition::hasSignal(TestCommandTargetWorkflow::class, 'rejected-by'));
        $this->assertFalse(WorkflowDefinition::hasSignal(TestCommandTargetWorkflow::class, 'missing'));

        $updateContract = [
            'name' => 'mark-approved',
            'parameters' => [[
                'name' => 'approved',
                'position' => 0,
                'required' => true,
                'variadic' => false,
                'default_available' => false,
                'default' => null,
                'type' => 'bool',
                'allows_null' => false,
            ]],
        ];

        $this->assertSame([$updateContract], $contract['update_contracts']);
        $this->assertSame($updateContract, WorkflowDefinition::updateContract(
            TestCommandTargetWorkflow::class,
            'mark-approved',
        ));
        $this->assertSame($updateContract, WorkflowDefinition::updateContract(
            TestCommandTargetWorkflow::class,
            'approve',
        ));
        $this->assertSame([
            'name' => 'mark-approved',
            'method' => 'approve',
        ], WorkflowDefinition::resolveUpdateTarget(TestCommandTargetWorkflow::class, 'mark-approved'));
        $this->assertSame([
            'name' => 'mark-approved',
            'method' => 'approve',
        ], WorkflowDefinition::resolveUpdateTarget(TestCommandTargetWorkflow::class, 'approve'));
        $this->assertNull(WorkflowDefinition::updateContract(TestCommandTargetWorkflow::class, 'missing'));
        $this->assertTrue(WorkflowDefinition::hasUpdateMethod(TestCommandTargetWorkflow::class, 'approve'));
        $this->assertFalse(WorkflowDefinition::hasUpdateMethod(TestCommandTargetWorkflow::class, 'missing'));
    }

    public function testItTreatsNonWorkflowClassesAsHavingNoDurableContract(): void
    {
        $class = stdClass::class;

        $this->assertSame([], WorkflowDefinition::queryMethods($class));
        $this->assertSame([], WorkflowDefinition::queryContracts($class));
        $this->assertNull(WorkflowDefinition::queryContract($class, 'query'));
        $this->assertNull(WorkflowDefinition::resolveQueryTarget($class, 'query'));
        $this->assertSame([], WorkflowDefinition::signalNames($class));
        $this->assertSame([], WorkflowDefinition::signalContracts($class));
        $this->assertNull(WorkflowDefinition::signalContract($class, 'signal'));
        $this->assertSame([], WorkflowDefinition::updateMethods($class));
        $this->assertSame([], WorkflowDefinition::updateContracts($class));
        $this->assertNull(WorkflowDefinition::updateContract($class, 'update'));
        $this->assertNull(WorkflowDefinition::resolveUpdateTarget($class, 'update'));
        $this->assertFalse(WorkflowDefinition::hasQueryMethod($class, 'query'));
        $this->assertFalse(WorkflowDefinition::hasSignal($class, 'signal'));
        $this->assertFalse(WorkflowDefinition::hasUpdateMethod($class, 'update'));
        $this->assertNull(WorkflowDefinition::fingerprint($class));

        WorkflowDefinition::assertWorkflowTypeRegistration('', TestCommandTargetWorkflow::class);
        WorkflowDefinition::assertWorkflowTypeRegistration('invalid-definition', $class);

        $this->assertTrue(true);
    }

    public function testCommandContractRejectsNonWorkflowClasses(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must extend');

        WorkflowDefinition::commandContract(stdClass::class);
    }

    public function testItIndexesFingerprintsAndRejectsConflictingTypeRegistrations(): void
    {
        $fingerprint = WorkflowDefinition::fingerprint(TestCommandTargetWorkflow::class);

        $this->assertNotNull($fingerprint);
        $this->assertSame(
            TestCommandTargetWorkflow::class,
            WorkflowDefinition::findClassByFingerprint($fingerprint),
        );
        $this->assertNull(WorkflowDefinition::findClassByFingerprint('sha256:missing'));

        WorkflowDefinition::assertWorkflowTypeRegistration(
            'coverage.workflow-definition',
            TestCommandTargetWorkflow::class,
        );
        WorkflowDefinition::assertWorkflowTypeRegistration(
            'coverage.workflow-definition',
            TestCommandTargetWorkflow::class,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cannot be re-registered');

        WorkflowDefinition::assertWorkflowTypeRegistration(
            'coverage.workflow-definition',
            AlternateWorkflowDefinition::class,
        );
    }

    public function testItExcludesContainerInjectedParametersFromPortableContracts(): void
    {
        $this->assertSame([[
            'name' => 'inspect',
            'parameters' => [
                [
                    'name' => 'required',
                    'position' => 0,
                    'required' => true,
                    'variadic' => false,
                    'default_available' => false,
                    'default' => null,
                    'type' => 'string',
                    'allows_null' => false,
                ],
                [
                    'name' => 'optional',
                    'position' => 1,
                    'required' => false,
                    'variadic' => false,
                    'default_available' => true,
                    'default' => null,
                    'type' => '?int',
                    'allows_null' => true,
                ],
                [
                    'name' => 'tags',
                    'position' => 2,
                    'required' => false,
                    'variadic' => true,
                    'default_available' => false,
                    'default' => null,
                    'type' => 'string',
                    'allows_null' => false,
                ],
            ],
        ]], WorkflowDefinition::queryContracts(InjectedParameterWorkflowDefinition::class));

        $this->assertSame([[
            'name' => 'change',
            'parameters' => [
                [
                    'name' => 'approved',
                    'position' => 0,
                    'required' => true,
                    'variadic' => false,
                    'default_available' => false,
                    'default' => null,
                    'type' => 'bool',
                    'allows_null' => false,
                ],
                [
                    'name' => 'count',
                    'position' => 1,
                    'required' => false,
                    'variadic' => false,
                    'default_available' => true,
                    'default' => 2,
                    'type' => 'int',
                    'allows_null' => false,
                ],
            ],
        ]], WorkflowDefinition::updateContracts(InjectedParameterWorkflowDefinition::class));
    }

    public function testItRejectsDuplicateDurableSignalNames(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('duplicate durable signal name [duplicate]');

        WorkflowDefinition::signalNames(DuplicateSignalWorkflowDefinition::class);
    }

    public function testSignalContractsRejectDuplicateDurableSignalNames(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('duplicate durable signal name [duplicate]');

        WorkflowDefinition::signalContracts(DuplicateSignalWorkflowDefinition::class);
    }

    public function testItRejectsDuplicateDurableQueryNames(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('duplicate durable query name [duplicate]');

        WorkflowDefinition::queryMethods(DuplicateQueryWorkflowDefinition::class);
    }

    public function testItRejectsDuplicateDurableUpdateNames(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('duplicate durable update name [duplicate]');

        WorkflowDefinition::updateMethods(DuplicateUpdateWorkflowDefinition::class);
    }

    public function testFingerprintsIncludeTraitAndInheritedWorkflowSources(): void
    {
        $baseFingerprint = WorkflowDefinition::fingerprint(TraitedWorkflowDefinition::class);
        $childFingerprint = WorkflowDefinition::fingerprint(InheritedWorkflowDefinition::class);

        $this->assertNotNull($baseFingerprint);
        $this->assertNotNull($childFingerprint);
        $this->assertNotSame($baseFingerprint, $childFingerprint);
    }
}

final class AlternateWorkflowDefinition extends Workflow
{
    public function handle(): string
    {
        return 'alternate';
    }
}

final class InjectedParameterWorkflowDefinition extends Workflow
{
    public function handle(): void
    {
    }

    #[QueryMethod('inspect')]
    public function inspect(stdClass $dependency, string $required, ?int $optional = null, string ...$tags): void
    {
    }

    #[UpdateMethod('change')]
    public function change(stdClass $dependency, bool $approved, int $count = 2): void
    {
    }
}

#[Signal('duplicate')]
#[Signal('duplicate')]
final class DuplicateSignalWorkflowDefinition extends Workflow
{
    public function handle(): void
    {
    }
}

final class DuplicateQueryWorkflowDefinition extends Workflow
{
    public function handle(): void
    {
    }

    #[QueryMethod('duplicate')]
    public function firstQuery(): void
    {
    }

    #[QueryMethod('duplicate')]
    public function secondQuery(): void
    {
    }
}

final class DuplicateUpdateWorkflowDefinition extends Workflow
{
    public function handle(): void
    {
    }

    #[UpdateMethod('duplicate')]
    public function firstUpdate(): void
    {
    }

    #[UpdateMethod('duplicate')]
    public function secondUpdate(): void
    {
    }
}

trait WorkflowDefinitionFingerprintTrait
{
    public function sharedBehavior(): string
    {
        return 'shared';
    }
}

class TraitedWorkflowDefinition extends Workflow
{
    use WorkflowDefinitionFingerprintTrait;

    public function handle(): string
    {
        return $this->sharedBehavior();
    }
}

final class InheritedWorkflowDefinition extends TraitedWorkflowDefinition
{
    public function handle(): string
    {
        return parent::handle() . '-child';
    }
}
