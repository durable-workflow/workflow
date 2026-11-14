<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Fixtures\V2\TestGreetingWorkflow;
use Tests\TestCase;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Binding\BindingAuthorization;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Binding\ChildReservation;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;
use Workflow\V2\StateMachine\Binding\NeverAdmittedChild;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\Support\ChildWorkflowOptions;
use Workflow\V2\Support\TypeRegistry;

final class ChildReservationRecoveryTest extends TestCase
{
    public function testOriginalDescriptorCanBeLoadedAndClosedAfterItsDefinitionChanges(): void
    {
        $parent = $this->parent();
        $reservation = $this->reserve($parent);
        $historical = new ChildReservation(
            $reservation->identity,
            $reservation->workflowClass,
            'sha256:' . str_repeat('f', 64),
            $reservation->authorizationVersion,
        );
        $instance = WorkflowInstance::query()->findOrFail($reservation->childInstanceId());
        $state = json_decode($instance->getRawOriginal('native_child_reservation'), true, flags: JSON_THROW_ON_ERROR);
        $state['reservation'] = $historical->toArray();
        WorkflowInstance::query()->whereKey($instance->id)->toBase()->update([
            'native_child_reservation' => json_encode($state, JSON_THROW_ON_ERROR),
        ]);
        $parent->forceFill([
            'status' => RunStatus::Completed,
        ])->save();

        try {
            $this->reserve($parent);
            $this->fail('A new deployed definition silently replaced the historical reservation.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('different immutable descriptor', $error->getMessage());
        }
        $loaded = $this->store()
            ->load($reservation->identity, 'trusted-owner');
        $this->assertSame($historical->toArray(), $loaded->toArray());
        $closed = $this->store()
            ->close($loaded, 'trusted-owner');
        $this->assertInstanceOf(NeverAdmittedChild::class, $closed);
        $again = $this->store()
            ->load($reservation->identity, 'trusted-owner');
        $this->assertSame($loaded->toArray(), $again->toArray());
        $this->assertSame($closed->closureId, $this->store()->close($again, 'trusted-owner')->closureId);
    }

    public function testLoadingAStoredDescriptorDoesNotAutoloadItsHistoricalClass(): void
    {
        $reservation = $this->reserve($this->parent());
        $historicalClass = 'Tests\\Fixtures\\V2\\UnavailableHistoricalReservedChild';
        $historical = new ChildReservation(
            $reservation->identity,
            $historicalClass,
            $reservation->definitionFingerprint,
            $reservation->authorizationVersion,
        );
        $instance = WorkflowInstance::query()->findOrFail($reservation->childInstanceId());
        $state = json_decode($instance->getRawOriginal('native_child_reservation'), true, flags: JSON_THROW_ON_ERROR);
        $state['reservation'] = $historical->toArray();
        WorkflowInstance::query()->whereKey($instance->id)->toBase()->update([
            'workflow_class' => $historicalClass,
            'native_child_reservation' => json_encode($state, JSON_THROW_ON_ERROR),
        ]);
        $autoloaded = [];
        $loader = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($loader);
        try {
            $loaded = $this->store()
                ->load($reservation->identity, 'trusted-owner');
            $this->assertSame($historical->toArray(), $loaded->toArray());
            $this->assertInstanceOf(NeverAdmittedChild::class, $this->store()->close($loaded, 'trusted-owner'));
        } finally {
            spl_autoload_unregister($loader);
        }
        $this->assertNotContains($historicalClass, $autoloaded);
    }

    public function testLoadingRejectsAChangedDescriptorWithTheSameLookupKey(): void
    {
        $reservation = $this->reserve($this->parent());
        $changed = $reservation->identity->toArray();
        $changed['requiredOutcome'] = 'substituted-outcome';
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('differs from the original binding');
        $this->store()
            ->load(BindingIdentity::fromArray($changed), 'trusted-owner');
    }

    public function testLoadingRequiresAnExistingReservationAndCannotCreateOne(): void
    {
        $identity = $this->identity($this->parent());
        try {
            $this->store()
                ->load($identity, 'trusted-owner');
            $this->fail('Lookup absence became a child reservation.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame(1, WorkflowInstance::query()->count());
            $this->assertSame(1, WorkflowRun::query()->count());
        }
    }

    public function testLoadingReauthorizesItsOwnOperationWithoutReplacingThePinnedPolicy(): void
    {
        $reservation = $this->reserve($this->parent());
        $authorization = new class() implements BindingAuthorization {
            public array $calls = [];

            public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
            {
                $this->calls[] = [$identity->toArray(), $principal, $operation, DB::transactionLevel()];
                return 'current-host-policy-v2';
            }
        };
        $loaded = (new ChildReservationStore($authorization))->load($reservation->identity, 'current-principal');
        $this->assertSame([
            [$reservation->identity->toArray(), 'current-principal', 'load', 0],
        ], $authorization->calls);
        $this->assertSame($reservation->toArray(), $loaded->toArray());
        $this->assertSame('host-policy-v1', $loaded->authorizationVersion);
    }

    public function testForeignOwnerIsRejectedBeforeProtectedReservationDecoding(): void
    {
        $reservation = $this->reserve($this->parent());
        WorkflowInstance::query()->whereKey($reservation->childInstanceId())->toBase()->update([
            'namespace' => 'foreign-tenant',
            'native_child_reservation' => '{invalid-protected-json',
        ]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('does not match the original owner');
        $this->store()
            ->load($reservation->identity, 'trusted-owner');
    }

    private function store(bool $allowed = true): ChildReservationStore
    {
        return new ChildReservationStore(new class($allowed) implements BindingAuthorization {
            public function __construct(
                private readonly bool $allowed
            ) {
            }

            public function assertAllowed(BindingIdentity $identity, string $principal, string $operation): string
            {
                if (! $this->allowed || $principal !== 'trusted-owner' || $identity->namespace !== 'tenant-a') {
                    throw new LogicException('binding_access_refused');
                }
                return 'host-policy-v1';
            }
        });
    }

    private function parent(): WorkflowRun
    {
        $instance = WorkflowInstance::query()->create([
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => TypeRegistry::for(TestGreetingWorkflow::class),
            'namespace' => 'tenant-a',
            'run_count' => 1,
            'reserved_at' => now(),
        ]);
        $run = WorkflowRun::query()->create([
            'workflow_instance_id' => $instance->id,
            'run_number' => 1,
            'workflow_class' => TestGreetingWorkflow::class,
            'workflow_type' => $instance->workflow_type,
            'namespace' => 'tenant-a',
            'status' => RunStatus::Running,
            'last_history_sequence' => 0,
            'started_at' => now(),
        ]);
        $instance->forceFill([
            'current_run_id' => $run->id,
        ])->save();
        return $run;
    }

    private function identity(WorkflowRun $parent): BindingIdentity
    {
        return new BindingIdentity(
            new ExecutionReference('app', 'business-1', 'execution-1', str_repeat('a', 64), 'ready', 'revision-1'),
            'tenant-a',
            $parent->workflow_instance_id,
            $parent->id,
            'shipment',
            'entry-1',
            'delivering',
            TypeRegistry::for(TestGreetingWorkflow::class),
            str_repeat('b', 64),
            'delivered',
            ChildReservation::inputFingerprint(['Taylor']),
            new ChildWorkflowOptions(connection: 'database', queue: 'bound-child'),
        );
    }

    private function reserve(WorkflowRun $parent): ChildReservation
    {
        return $this->store()
            ->reserve($this->identity($parent), TestGreetingWorkflow::class, 'trusted-owner');
    }
}
