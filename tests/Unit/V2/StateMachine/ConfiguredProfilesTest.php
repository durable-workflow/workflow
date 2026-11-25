<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\ConfiguredStateMachines\Catalogue;
use DurableWorkflow\Examples\ConfiguredStateMachines\Ownership;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Workflow\V2\StateMachine\Binding\BindingIdentity;
use Workflow\V2\StateMachine\Execution\ExecutionReference;
use Workflow\V2\StateMachine\Rules\RecordedFacts;
use Workflow\V2\StateMachine\Rules\Verdict;

require_once __DIR__ . '/../../../../examples/configured-state-machines/bootstrap.php';

final class ConfiguredProfilesTest extends TestCase
{
    public static function originalProfiles(): array
    {
        // Captured from accepted 8d1162d2 before adding version two.
        return [
            ['order', 'prepare', 'fead53d344a21edd19c69e87a5c3c4ba579699c62684241ca32522c6b645e6dd'],
            ['order', 'imported', '7b3f62ce2dc0c01afae19fb2e96a393c3e4248cf7d2e6c9c1ee581ff8440c8c6'],
            ['shipment', 'prepare', '705b834d81f1d94b9330b5bb74318211fa08b89c390f0e9350fad38513c13a36'],
            ['shipment', 'imported', '368f4b5795fbbb0354b70f533d3c4dd6173f707f2f6c01c0a166303e4fae0d32'],
        ];
    }

    #[DataProvider('originalProfiles')]
    public function testTheOriginalExampleProfileIsNotReinterpreted(
        string $role,
        string $integration,
        string $fingerprint
    ): void {
        $original = Catalogue::$role($integration, '1');
        $this->assertSame($fingerprint, $original->fingerprint());
        $this->assertNull($original->definition);
        $this->assertSame($fingerprint, Catalogue::$role($integration)->fingerprint());
        $this->assertNotSame($fingerprint, Catalogue::$role($integration, '2')->fingerprint());
    }

    public function testBothIntegrationsShareDefinitionMeaningsAndKeepForwardPermissionSeparateFromCancellation(): void
    {
        $prepare = Catalogue::order('prepare', '2');
        $imported = Catalogue::order('imported', '2');
        $this->assertSame($prepare->definition->fingerprint(), $imported->definition->fingerprint());
        $this->assertSame($prepare->definition->toArray()['states'], $imported->definition->toArray()['states']);
        $this->assertNotSame($prepare->fingerprint(), $imported->fingerprint());
        $facts = new RecordedFacts('cancel-proof', [
            'model_consistent' => true,
            'enabled' => true,
            'forward_open' => false,
            'cancellation_requested' => true,
            'scope_verified' => true,
            'slot_available' => false,
        ], [
            'model_consistent' => 'row-v2',
            'enabled' => 'row-v2',
            'forward_open' => 'fence-v2',
            'cancellation_requested' => 'cancel-v2',
            'scope_verified' => 'ledger-v2',
            'slot_available' => 'binding-v2',
        ]);
        foreach ([$prepare, $imported] as $profile) {
            $registry = Catalogue::registry();
            $this->assertSame(Verdict::Satisfied, $profile->definition->assess($registry, $facts)->decision->verdict);
            $this->assertSame(
                Verdict::Satisfied,
                $registry->evaluateCondition($profile->condition('cancel.target'), $facts)
->decision->verdict
            );
            $this->assertSame(
                Verdict::Unsatisfied,
                $registry->evaluateCondition($profile->condition('begin.permission'), $facts)
->decision->verdict
            );
        }
    }

    public function testTheVersionTwoSlotCannotAdoptAVersionOneShipmentPolicy(): void
    {
        $profile = Catalogue::order('prepare', '2');
        $execution = new ExecutionReference(
            Ownership::APPLICATION,
            'case-aaaaaaaaaaaaaaaa-g1',
            'root-1.begin.1',
            $profile->fingerprint(),
            'received',
            '1'
        );
        $binding = Ownership::binding($execution, 'root-1', 'prepare', '2');
        $profile->definition->assertBinding($profile, $binding, Catalogue::shipment('prepare', '2'));
        Catalogue::machines()->assertBinding($profile, $binding, Catalogue::shipment('prepare', '2'));
        $this->assertSame(1, Catalogue::machines()->childDepth($profile->definition));
        $data = $binding->toArray();
        $data['childPolicyFingerprint'] = Catalogue::shipment('prepare', '1')->fingerprint();
        $this->expectException(LogicException::class);
        $profile->definition->assertBinding(
            $profile,
            BindingIdentity::fromArray($data),
            Catalogue::shipment('prepare', '1')
        );
    }
}
