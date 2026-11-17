<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\RootCancellationStore;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;
use Workflow\V2\Compensation\Admission\NeverAdmittedRoot;
use Workflow\V2\Compensation\Admission\RootBinding;
use Workflow\V2\Compensation\Admission\RootIdentity;
use Workflow\V2\Compensation\Admission\RootReservationStore;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

final class V2RootStartupCancellationTest extends TestCase
{
    use \Tests\Support\ProviderMachineFixture;

    public function testCancellationBeforeFirstReservationCommitsOneOriginalBusinessDecisionAndPublication(): void
    {
        $key = $this->terms->subjectKey();
        $result = app(RootCancellationStore::class)->request($key, 'original-cancel');
        $this->assertSame('cancelled', $result['status']);
        $this->assertSame('never_admitted', $result['proof']['kind']);
        $this->assertSame($result, app(RootCancellationStore::class)->request($key, 'original-cancel'));
        $this->assertCancelledOnce($result);
        $this->assertSame(0, WorkflowRun::query()->count());
        $this->assertSame('root_never_admitted', DB::table('workflow_compensation_scopes')->value('status'));
        $roots = app(RootReservationStore::class);
        $identity = $this->store->authority->roots->identity($key);
        $this->assertInstanceOf(
            NeverAdmittedRoot::class,
            $roots->start($roots->load($identity, 'provider-worker'), 'provider-worker', [$key])
        );
    }

    public function testLostReservationAcknowledgementKeepsItsOriginalRunAndNeverAdmittedReceipt(): void
    {
        $key = $this->terms->subjectKey();
        $original = app(RootReservationStore::class)->reserve(
            $this->store->authority->roots->identity($key),
            'provider-worker'
        );
        $result = app(RootCancellationStore::class)->request($key, 'after-lost-reserve-ack');
        $this->assertSame($original->originalRunId, $result['proof']['reservedRunId']);
        $this->assertSame($original->fingerprint(), $result['proof']['reservationFingerprint']);
        $this->assertCancelledOnce($result);
    }

    public function testFirstCancellationUsesThePersistedUnavailableHistoricalDefinitionAsData(): void
    {
        $key = $this->terms->subjectKey();
        // This retained template represents the original deployment. The current
        // process deliberately has no class implementation for that descriptor.
        $value = $this->store->authority->roots->identity($key)
            ->toArray();
        $value['workflowClass'] = 'Tests\\UnavailableOriginalProviderOrder';
        $identity = RootIdentity::fromArray($value);
        $payload = PortableDescriptor::encode($identity->toArray());
        DB::table('provider_machine_root_intents')->where('id', $key)->update([
            'identity' => $payload,
            'identity_sha256' => hash('sha256', $payload),
        ]);
        $autoloaded = [];
        $loader = static function (string $class) use (&$autoloaded): void {
            $autoloaded[] = $class;
        };
        spl_autoload_register($loader);
        try {
            $result = app(RootCancellationStore::class)->request($key, 'cancel-old-deployment');
            $this->assertSame('never_admitted', $result['proof']['kind']);
            $this->assertCancelledOnce($result);
        } finally {
            spl_autoload_unregister($loader);
        }
        $this->assertNotContains($identity->workflowClass, $autoloaded);
    }

    public function testPublicationFailureRollsBackBusinessDecisionAndReplaysTheCommittedNativeClosure(): void
    {
        $key = $this->terms->subjectKey();
        $armed = true;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($sql), 'insert') && str_contains(
                $sql,
                'example_machine_publications'
            )) {
                $armed = false;
                throw new LogicException('injected_startup_publication_failure');
            }
        });
        try {
            app(RootCancellationStore::class)->request($key, 'publication-lost');
            $this->fail('The final publication failure was ignored.');
        } catch (LogicException $error) {
            $this->assertSame('injected_startup_publication_failure', $error->getMessage());
        }
        $root = $this->store->authority->subject($key, 'order');
        $this->assertSame('received', $root->state);
        $this->assertSame(1, (int) $root->revision);
        $this->assertTrue((bool) $root->cancellation_requested);
        $this->assertSame(0, DB::table('example_machine_decisions')->count());
        $this->assertSame(0, DB::table('example_machine_publications')->count());
        $identity = $this->store->authority->roots->identity($key);
        $closed = app(RootReservationStore::class)->closeIntent($identity, 'provider-worker');
        $this->assertInstanceOf(NeverAdmittedRoot::class, $closed);
        $result = app(RootCancellationStore::class)->request($key, 'publication-lost');
        $this->assertSame($closed->closureId, $result['proof']['closureId']);
        $this->assertCancelledOnce($result);
    }

    public function testChangedCancellationContentCannotReplaceTheOriginalDecision(): void
    {
        $key = $this->terms->subjectKey();
        $original = app(RootCancellationStore::class)->request($key, 'original-request');
        try {
            app(RootCancellationStore::class)->request($key, 'replacement-request');
            $this->fail('A different request replaced the original cancellation.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('request changed content', $error->getMessage());
        }
        $this->assertSame($original, app(RootCancellationStore::class)->request($key, 'original-request'));
        $this->assertCancelledOnce($original);
    }

    public function testAdmittedRootWithoutAnInitializerUsesItsGenuineVerifiedScopeBeforeBusinessCancellation(): void
    {
        $key = $this->terms->subjectKey();
        $identity = $this->store->authority->roots->identity($key);
        $roots = app(RootReservationStore::class);
        $reservation = $roots->reserve($identity, 'provider-worker');
        $binding = $roots->start($reservation, 'provider-worker', [$key]);
        $this->assertInstanceOf(RootBinding::class, $binding);
        $this->assertSame(0, DB::table('provider_machine_actors')->count());
        $first = app(RootCancellationStore::class)->request($key, 'before-original-initializer');
        $this->assertSame('requested', $first['status']);
        $this->assertSame('origin', $first['route']['route']);
        $this->drain();
        $origin = WorkflowRun::query()->findOrFail($reservation->originalRunId);
        $this->assertTrue($origin->status->isTerminal(), $this->progress());
        $this->assertSame(0, DB::table('provider_machine_actors')->count());
        $second = app(RootCancellationStore::class)->request($key, 'before-original-initializer');
        $this->assertSame('requested', $second['status']);
        $this->assertSame('recovery', $second['route']['route']);
        $this->drain();
        $result = app(RootCancellationStore::class)->request($key, 'before-original-initializer');
        $this->assertSame('cancelled', $result['status'], $this->progress());
        $this->assertSame('verified_original_scope', $result['proof']['kind']);
        $this->assertSame($binding->toArray(), $result['proof']['binding']);
        $metadata = (new ScopeRepository())->metadata($identity->scope);
        $this->assertSame('verified', $metadata['status']);
        $this->assertSame($reservation->originalRunId, $metadata['original_run_id']);
        $this->assertCancelledOnce($result);
        $this->assertSame(1, WorkflowRun::query()->where('workflow_instance_id', $identity->instanceId())->count());
        try {
            $this->store->initialize(WorkflowRun::query()->findOrFail($reservation->originalRunId), [$key]);
            $this->fail('Late owner initialization mutated the settled startup cancellation.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('already committed', $error->getMessage());
        }
        $this->assertSame(0, DB::table('provider_machine_actors')->count());
    }

    public function testWorkflowReplayCannotWriteOrReadRootBusinessStorageThroughTheHostApi(): void
    {
        $key = $this->terms->subjectKey();
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        foreach (['cancel', 'identity'] as $operation) {
            $fiber = new \Fiber(function () use ($key, $operation): void {
                \Workflow\V2\Support\WorkflowFiberContext::enter();
                try {
                    $operation === 'cancel'
                        ? app(RootCancellationStore::class)->request($key, 'replay-must-refuse')
                        : $this->store->authority->roots->identity($key);
                    $this->fail('A replay frame entered root business storage.');
                } catch (LogicException $error) {
                    $this->assertStringContainsString('outside replay', $error->getMessage());
                } finally {
                    \Workflow\V2\Support\WorkflowFiberContext::leave();
                }
            });
            $fiber->start();
        }
        $this->assertSame([], $queries);
    }

    public function testRecoveryFailureNeedsAnExplicitFreshScopeKeyWhileKeepingTheOriginalBusinessIntent(): void
    {
        $key = $this->terms->subjectKey();
        $identity = $this->store->authority->roots->identity($key);
        $roots = app(RootReservationStore::class);
        $reservation = $roots->reserve($identity, 'provider-worker');
        $binding = $roots->start($reservation, 'provider-worker', [$key]);
        $this->assertInstanceOf(RootBinding::class, $binding);
        $inner = app(\Workflow\V2\Compensation\Security\ScopeAuthorization::class);
        $policy = new class($inner) implements \Workflow\V2\Compensation\Security\ScopeAuthorization {
            public bool $refuseRecovery = true;

            public function __construct(
                private readonly \Workflow\V2\Compensation\Security\ScopeAuthorization $inner
            ) {
            }

            public function assertAllowed(
                \Workflow\V2\Compensation\Definition\ScopeIdentity $scope,
                string $principal,
                string $operation
            ): string {
                if ($this->refuseRecovery && $operation === 'compensate') {
                    throw new LogicException('injected_startup_recovery_refusal');
                }
                return $this->inner->assertAllowed($scope, $principal, $operation);
            }
        };
        $this->app->instance(\Workflow\V2\Compensation\Security\ScopeAuthorization::class, $policy);
        $this->app->forgetInstance(\Workflow\V2\Compensation\Recovery\ScopeRecovery::class);
        $this->app->forgetInstance(\Workflow\V2\Compensation\Security\ScopeBoundary::class);
        app(RootCancellationStore::class)->request($key, 'fixed-business-request', 'scope-attempt-1');
        $this->drain();
        app(RootCancellationStore::class)->request($key, 'fixed-business-request', 'scope-attempt-1');
        $this->drain();
        $failed = app(RootCancellationStore::class)->request($key, 'fixed-business-request', 'scope-attempt-1');
        $this->assertSame('failed', $failed['route']['recoveryStatus'], $this->progress());
        $this->assertSame('submit_fresh_authorized_request', $failed['route']['nextAction']);
        $firstRecovery = $failed['route']['runId'];
        $this->assertSame(2, WorkflowRun::query()->count());
        $this->assertSame('received', $this->store->authority->subject($key, 'order')->state);
        $this->assertSame(0, DB::table('example_machine_publications')->count());
        $policy->refuseRecovery = false;
        $fresh = app(RootCancellationStore::class)->request($key, 'fixed-business-request', 'scope-attempt-2');
        $this->assertNotSame($firstRecovery, $fresh['route']['runId']);
        $started = \Workflow\V2\Models\WorkflowHistoryEvent::query()->where('workflow_run_id', $fresh['route']['runId'])
            ->where('event_type', \Workflow\V2\Enums\HistoryEventType::WorkflowStarted)->sole();
        $this->assertSame(
            $firstRecovery,
            $started->payload['compensation_recovery']['intent']['expected_predecessor_run_id']
        );

        $this->drain();
        $result = app(RootCancellationStore::class)->request($key, 'fixed-business-request', 'scope-attempt-2');
        $this->assertSame('cancelled', $result['status'], $this->progress());
        $this->assertSame($binding->toArray(), $result['proof']['binding']);
        $this->assertSame(3, WorkflowRun::query()->count());
        $this->assertCancelledOnce($result);
    }

    public static function uncertainBusiness(): array
    {
        return [['state'], ['revision'], ['child'], ['actor'], ['decision'], ['publication']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('uncertainBusiness')]
    public function testNativeNonAdmissionCannotEraseEarlierBusinessUncertainty(string $mode): void
    {
        $key = $this->terms->subjectKey();
        $db = $this->store->authority->db;
        $rootId = Authority::subjectId($key, 'order');
        if ($mode === 'state') {
            $db->table('example_machine_subjects')
                ->where('id', $rootId)
                ->update([
                    'state' => 'paid',
                ]);
        } elseif ($mode === 'revision') {
            $db->table('example_machine_subjects')
                ->where('id', $rootId)
                ->update([
                    'revision' => 2,
                ]);
        } elseif ($mode === 'child') {
            $db->table('example_machine_subjects')
                ->insert([
                    'id' => Authority::subjectId($key, 'payment'),
                    'scope' => \DurableWorkflow\Examples\ProviderStateMachines\Catalogue::APPLICATION,
                    'subject' => Authority::subjectName($key, 'payment'),
                    'principal' => 'provider-worker',
                    'authority_version' => $this->store->authority->grantFor($key, 'payment'),
                    'state' => 'received',
                    'revision' => 1,
                    'cancellation_requested' => false,
                ]);
        } elseif ($mode === 'actor') {
            $db->table('provider_machine_actors')
                ->insert([
                    'id' => $rootId,
                    'root_key' => $key,
                    'role' => 'order',
                    'native_run' => 'uncertain-old-run',
                    'scope' => '{}',
                ]);
        } elseif ($mode === 'decision') {
            $db->table('example_machine_decisions')
                ->insert([
                    'id' => str_repeat('a', 64),
                    'subject_id' => $rootId,
                    'request_sha256' => str_repeat('b', 64),
                    'status' => 'committed',
                    'reason' => 'earlier-uncertain-decision',
                ]);
        } else {
            $db->table('example_machine_publications')
                ->insert([
                    'id' => str_repeat('c', 64),
                    'subject_id' => $rootId,
                    'payload' => '{}',
                ]);
        }
        try {
            app(RootCancellationStore::class)->request($key, 'do-not-erase-business-uncertainty');
            $this->fail('A native non-admission receipt erased independent business uncertainty.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('untouched original business generation', $error->getMessage());
        }
        $root = $this->store->authority->subject($key, 'order');
        $this->assertNotSame('cancelled', $root->state);
        $this->assertSame($mode === 'decision' ? 1 : 0, $db->table('example_machine_decisions')->count());
        $this->assertSame($mode === 'publication' ? 1 : 0, $db->table('example_machine_publications')->count());
        $this->assertSame('root_never_admitted', $db->table('workflow_compensation_scopes')->value('status'));
        $this->assertSame([], $this->effects());
    }

    public function testStartupCompensationPermissionRequiresTheCommittedBusinessFence(): void
    {
        $key = $this->terms->subjectKey();
        $identity = $this->store->authority->roots->identity($key);
        $roots = app(RootReservationStore::class);
        $reservation = $roots->reserve($identity, 'provider-worker');
        $this->assertInstanceOf(RootBinding::class, $roots->start($reservation, 'provider-worker', [$key]));
        try {
            $this->store->authority->authorizeScope($identity->scope, 'provider-worker', 'compensate');
            $this->fail('An unfenced business root obtained startup compensation authority.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('committed business cancellation fence', $error->getMessage());
        }
        $this->assertSame('open', DB::table('workflow_compensation_scopes')->value('status'));
    }

    public function testAValidBusinessGrantCannotAuthorizeAnUnrelatedNativeRootDefinition(): void
    {
        $key = $this->terms->subjectKey();
        $value = $this->store->authority->roots->identity($key)
            ->toArray();
        $value['workflowClass'] = 'Tests\\UnauthorizedSubstitutedRoot';
        $identity = RootIdentity::fromArray($value);
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void {
            if (str_contains($event->sql, 'workflow_instances') || str_contains(
                $event->sql,
                'workflow_compensation_scopes'
            )) {
                $queries[] = $event->sql;
            }
        });
        try {
            app(RootReservationStore::class)->reserve($identity, 'provider-worker');
            $this->fail('A separately selected root definition was authorized for this business owner.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('relationship was substituted', $error->getMessage());
        }
        $this->assertSame([], $queries);
    }

    public function testFreshApplicationRecoversTheLostBusinessCommitUsingTheUnavailableOriginalIntent(): void
    {
        $key = $this->terms->subjectKey();
        $value = $this->store->authority->roots->identity($key)
            ->toArray();
        $value['workflowClass'] = 'Tests\\UnavailableOriginalProviderOrder';
        $payload = PortableDescriptor::encode(RootIdentity::fromArray($value)->toArray());
        DB::table('provider_machine_root_intents')->where('id', $key)->update([
            'identity' => $payload,
            'identity_sha256' => hash('sha256', $payload),
        ]);
        $armed = true;
        DB::connection()->beforeExecuting(static function (string $sql) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($sql), 'insert') && str_contains(
                $sql,
                'example_machine_publications'
            )) {
                $armed = false;
                throw new LogicException('injected_process_loss_before_publication');
            }
        });
        try {
            app(RootCancellationStore::class)->request($key, 'durably-recorded-original-request');
            $this->fail('The original process-loss cut was ignored.');
        } catch (LogicException $error) {
            $this->assertSame('injected_process_loss_before_publication', $error->getMessage());
        }
        $identity = $this->store->authority->roots->identity($key);
        $closed = app(RootReservationStore::class)->closeIntent($identity, 'provider-worker');
        $this->assertInstanceOf(NeverAdmittedRoot::class, $closed);
        $input = tempnam(sys_get_temp_dir(), 'provider-root-input-');
        $report = tempnam(sys_get_temp_dir(), 'provider-root-report-');
        chmod($input, 0600);
        chmod($report, 0600);
        try {
            file_put_contents($input, json_encode([
                'key' => $key,
                'providerPath' => $this->providerPath,
                'database' => DB::connection()->getConfig(),
                'report' => $report,
            ], JSON_THROW_ON_ERROR));
            $process = new \Symfony\Component\Process\Process([
                PHP_BINARY, 'vendor/bin/phpunit', 'tests/Support/ProviderRootCancellationRecoveryProcessTest.php',
            ], dirname(__DIR__, 3), [
                'DWF_PROVIDER_ROOT_RECOVERY_INPUT' => $input,
            ], timeout: 30);
            $process->mustRun();
            $recovered = json_decode(file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertNotSame(getmypid(), $recovered['pid']);
            $this->assertFalse($recovered['originalClassAutoloaded']);
            $this->assertSame(0, $recovered['providerRequests']);
            $this->assertSame($closed->closureId, $recovered['result']['proof']['closureId']);
            $this->assertCancelledOnce($recovered['result']);
            $this->assertSame(
                $recovered['result'],
                app(RootCancellationStore::class)->request($key, 'durably-recorded-original-request')
            );
        } finally {
            unlink($input);
            unlink($report);
        }
    }

    protected function reservedRoot(): bool
    {
        return true;
    }

    private function assertCancelledOnce(array $result): void
    {
        $key = $this->terms->subjectKey();
        $root = $this->store->authority->subject($key, 'order');
        $this->assertSame('cancelled', $root->state);
        $this->assertSame(2, (int) $root->revision);
        $this->assertTrue((bool) $root->cancellation_requested);
        $this->assertSame(
            1,
            DB::table('example_machine_decisions')->where('subject_id', Authority::subjectId($key, 'order'))->count()
        );
        $publications = DB::table('example_machine_publications')->where('subject_id', $root->id)->get();
        $this->assertCount(1, $publications);
        $this->assertSame(PortableDescriptor::encode($result), $publications[0]->payload);
        $this->assertSame([], $this->effects());
    }
}
