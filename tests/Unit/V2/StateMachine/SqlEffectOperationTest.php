<?php

declare(strict_types=1);

namespace Tests\Unit\V2\StateMachine;

use DurableWorkflow\Examples\BusinessStateMachines\ProfileCatalogue;
use DurableWorkflow\Examples\BusinessStateMachines\SqlEffectOperation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use Orchestra\Testbench\TestCase;
use Tests\Fixtures\V2\BusinessCommitFixture;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Execution\OperationContext;

require_once __DIR__ . '/../../../../examples/business-state-machines/ProfileCatalogue.php';
require_once __DIR__ . '/../../../../examples/business-state-machines/SqlEffectOperation.php';

final class SqlEffectOperationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        class_exists(BusinessCommitFixture::class);
        foreach (['schema.php', 'provider-schema.php'] as $path) {
            $schema = require __DIR__ . '/../../../../examples/business-state-machines/' . $path;
            $schema(DB::connection()->getSchemaBuilder());
        }
        BusinessCommitFixture::seed(BusinessCommitFixture::context()->execution());
    }

    public function testUncertainForwardReconcilesTheOriginalEffectAcrossNewAttempts(): void
    {
        $operation = $this->operation();
        $original = $operation->execute($this->context());
        $retried = $operation->execute($this->context(uncertain: true));
        $this->assertSame($original->toArray(), $retried->toArray());
        $this->assertSame('active', $retried->value['status']);
        $this->assertSame(1, DB::table('example_machine_effects')->count());
    }

    public function testCancellationBeforeDispatchCreatesAnIrreversibleNonAdmissionReceipt(): void
    {
        DB::table('example_machine_subjects')->update([
            'cancellation_requested' => true,
        ]);
        $operation = $this->operation();
        $this->assertSame('absent', $operation->execute($this->context())->value['status']);
        // Even accidental later reopening of business work cannot reopen this original operation.
        DB::table('example_machine_subjects')->update([
            'cancellation_requested' => false,
        ]);
        $this->assertSame('absent', $operation->execute($this->context(uncertain: true))->value['status']);
        $this->assertSame(1, DB::table('example_machine_effects')->count());
        $this->assertSame(0, DB::table('example_machine_effects')->where('status', 'active')->count());
    }

    public function testCompensationClosesTheOriginalEffectAndPreventsLateRecreation(): void
    {
        $operation = $this->operation();
        $operation->execute($this->context());
        DB::table('example_machine_subjects')->update([
            'cancellation_requested' => true,
        ]);
        $resolution = $operation->compensate($this->context(uncertain: true));
        $this->assertTrue($resolution->verified);
        $this->assertSame('verified', $resolution->outcome);
        $this->assertNotEmpty($resolution->evidence);
        DB::table('example_machine_subjects')->update([
            'cancellation_requested' => false,
        ]);
        $this->assertSame('voided', $operation->execute($this->context())->value['status']);
        $this->assertSame(1, DB::table('example_machine_effects')->count());
        $this->assertSame(0, DB::table('example_machine_effects')->where('status', 'active')->count());
    }

    public function testCompensationBeforeTheFirstCallbackCommitsAForwardFence(): void
    {
        DB::table('example_machine_subjects')->update([
            'cancellation_requested' => true,
        ]);
        $operation = $this->operation();
        $resolution = $operation->compensate($this->context());
        $this->assertTrue($resolution->verified);
        $this->assertSame('verified', $resolution->outcome);
        $this->assertSame('absent', $operation->execute($this->context(uncertain: true))->value['status']);
        $this->assertSame(0, DB::table('example_machine_effects')->where('status', 'active')->count());
    }

    public function testCompensationCannotPretendBusinessCancellationWasAccepted(): void
    {
        $operation = $this->operation();
        $operation->execute($this->context());
        $resolution = $operation->compensate($this->context());
        $this->assertFalse($resolution->verified);
        $this->assertNotSame('verified', $resolution->outcome);
        $this->assertSame('business_cancellation_required', $resolution->reason);
        $this->assertSame('active', DB::table('example_machine_effects')->value('status'));
    }

    public function testAReceiptCannotBeReusedByAnotherSeparatelyAuthorizedScope(): void
    {
        $operation = $this->operation();
        $operation->execute($this->context());
        $this->expectExceptionMessage('The original SQL effect owner or status was substituted.');
        $operation->execute($this->context(key: 'provider-2'));
    }

    public function testAuthorityRevocationRefusesBeforeCreatingAnEffect(): void
    {
        DB::table('example_machine_subjects')->update([
            'authority_version' => 'grant-v2',
        ]);
        $operation = $this->operation();
        try {
            $operation->execute($this->context());
            $this->fail('Revoked work must not create an effect.');
        } catch (LogicException $error) {
            $this->assertSame('The SQL service subject authority changed.', $error->getMessage());
            $this->assertSame(0, DB::table('example_machine_effects')->count());
        }
    }

    public function testAHostRefusalPrecedesEveryProtectedQuery(): void
    {
        $operation = new SqlEffectOperation(DB::connection(), 'reserve', static function (): array {
            throw new LogicException('wrong_application_owner');
        });
        $context = $this->context();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        try {
            $operation->execute($context);
            $this->fail('The operation host refused this owner.');
        } catch (LogicException $error) {
            $this->assertSame('wrong_application_owner', $error->getMessage());
            $this->assertSame([], $queries);
        }
    }

    public function testBothIntegrationsShareStateMeaningsWithDifferentActionsAndEdges(): void
    {
        $prepare = ProfileCatalogue::select('prepare', '1');
        $imported = ProfileCatalogue::select('imported', '1');
        $this->assertSame($prepare->machine, $imported->machine);
        $this->assertSame($prepare->states, $imported->states);
        $this->assertNotSame($prepare->fingerprint(), $imported->fingerprint());
        $this->assertSame(['reserve', 'prepare'], ProfileCatalogue::actions('prepare', '1'));
        $this->assertSame(['import'], ProfileCatalogue::actions('imported', '1'));
        $this->assertSame([], $prepare->candidates('already-completed', 'received'));
        $this->assertCount(1, $imported->candidates('already-completed', 'received'));
    }

    public function testUnknownProfileVersionsDoNotSilentlyChooseTheNewestPolicy(): void
    {
        $this->expectExceptionMessage('The original integration profile is unavailable.');
        ProfileCatalogue::select('prepare', '2');
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'example');
        $app['config']->set('database.connections.example', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    private function operation(): SqlEffectOperation
    {
        return new SqlEffectOperation(DB::connection(), 'reserve', static function (
            OperationContext $context,
            string $operation,
        ): array {
            if ($context->scope->application !== 'example-app' || $context->scope->namespace !== 'demo'
                || ! in_array($context->scope->key, ['provider', 'provider-2'], true)
                || $context->scope->generation !== 'entry-1' || $context->originalRunId !== 'native-run-1'
                || ! in_array($operation, ['forward', 'compensate'], true)
                || $context->obligation->input['execution'] !== BusinessCommitFixture::context()->execution()->toArray()) {
                throw new LogicException('The original SQL service relationship was not authorized.');
            }
            return [
                'principal' => 'alice',
                'version' => 'grant-v1',
            ];
        });
    }

    private function context(bool $uncertain = false, string $key = 'provider'): OperationContext
    {
        return new OperationContext(
            new ScopeIdentity('example-app', 'demo', $key, 'entry-1'),
            new Obligation('reserve', 'example.action', '1', [
                'action' => 'reserve',
                'execution' => BusinessCommitFixture::context()->execution()->toArray(),
            ]),
            hash('sha256', 'original-operation'),
            'native-run-1',
            $uncertain ? 'replacement-activity' : 'original-activity',
            $uncertain ? 'replacement-attempt' : 'original-attempt',
            $uncertain,
        );
    }
}
