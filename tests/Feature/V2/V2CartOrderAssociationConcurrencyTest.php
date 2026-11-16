<?php

declare(strict_types=1);

namespace Tests\Feature\V2;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\IncomingPaymentProvider;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\CancellationStore;
use DurableWorkflow\Examples\ProviderStateMachines\CartStore;
use DurableWorkflow\Examples\ProviderStateMachines\FundingCatalogue;
use DurableWorkflow\Examples\ProviderStateMachines\FundingHandoffStore;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CartCheckoutMachineFixture;
use Tests\TestCase;
use Throwable;
use Workflow\V2\Compensation\Persistence\ScopeRepository;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\StateMachine\Binding\ChildBinding;
use Workflow\V2\StateMachine\Binding\ChildReservationStore;

require_once __DIR__ . '/../../../examples/provider-state-machines/bootstrap.php';

/** Source host-boundary races with genuine native purchase, Payment and provider evidence. */
final class V2CartOrderAssociationConcurrencyTest extends TestCase
{
    use CartCheckoutMachineFixture { setUp as private setUpCart; }

    protected function setUp(): void
    {
        $this->setUpCart();
        if (DB::connection()->getDriverName() !== 'pgsql' || ! function_exists('pcntl_fork')
            || ! function_exists('posix_kill')) {
            $this->markTestSkipped('PostgreSQL row-lock observation and process control are required.');
        }
    }

    public static function orderings(): array
    {
        return [
            'cancellation wins' => ['cancel', 'associate', ['requested', 'refused'], 0],
            'Order association wins' => ['associate', 'cancel', ['associated', 'requested'], 1],
        ];
    }

    #[DataProvider('orderings')]
    public function testCancellationAndOrderAssociationSerializeOnTheOriginalPurchase(
        string $winner,
        string $loser,
        array $expected,
        int $orders,
    ): void {
        $origin = $this->startCart();
        $key = $this->terms->subjectKey();
        $plan = $this->store->authority->plan($key);
        $reservations = app(ChildReservationStore::class);
        $reservation = $reservations->load(
            $plan->binding('payment'),
            $this->store->authority->principal,
            $plan->participation('payment')
        );
        $binding = $reservations->close($reservation, $this->store->authority->principal);
        $this->assertInstanceOf(ChildBinding::class, $binding);
        $value = $binding->toArray();
        $this->pay('cart-race-original-money', pump: false);
        $this->drain(static fn (): bool => app(FundingHandoffStore::class)->milestone(
            $origin->run(),
            $value,
            'confirm-receipt'
        )['confirmed']);
        $before = (new ScopeRepository())->inspect($plan->rootScope());
        $this->assertSame(0, DB::table('provider_cart_orders')->count());
        $this->assertSame(['reserve'], $this->effects());
        $this->assertSame($expected, $this->race($winner, $loser, $origin->runId(), $value));
        $this->assertSame($orders, DB::table('provider_cart_orders')->count());
        $this->assertTrue((bool) $this->store->authority->subject($key, 'order')->cancellation_requested);
        if ($orders === 1) {
            $order = app(CartStore::class)->order($key);
            $this->assertSame($origin->runId(), $order['rootRun']);
            $this->assertSame('initial', $order['selected']);
            $this->assertSame($binding->toArray(), ChildBinding::fromArray($order['paymentBinding'])->toArray());
        }
        $this->pump();
        $after = (new ScopeRepository())->inspect($plan->rootScope());
        $this->assertSame('verified', $after['status'], $this->progress());
        $this->assertSame($origin->runId(), $after['original_run_id']);
        $this->assertSame(array_column($before['obligations'], 'id'), array_column($after['obligations'], 'id'));
        $this->assertSame(['reserve', 'refund-incoming', 'release'], $this->effects());
        $this->assertTrue($this->provider->cartInventory()->inspect($this->cart->initial)['released']);
        $this->assertSame($orders, DB::table('provider_cart_orders')->count());
    }

    private function operate(string $operation, string $runId, array $binding): string
    {
        if ($operation === 'cancel') {
            return app(CancellationStore::class)->request(
                $this->terms->subjectKey(),
                $runId,
                'cart-order-association-race'
            )['status'];
        }
        try {
            app(CartStore::class)->associate(WorkflowRun::query()->findOrFail($runId), $binding, 'initial');
            return 'associated';
        } catch (LogicException $error) {
            if ($error->getMessage() !== 'The original cart was cancelled or changed before its protected write.') {
                throw $error;
            }
            return 'refused';
        }
    }

    private function reconnectCart(): void
    {
        DB::purge();
        DB::reconnect();
        $this->providerDb = new PDO('sqlite:' . $this->providerPath);
        $this->incoming = new IncomingPaymentProvider(
            $this->providerDb,
            $this->terms->client,
            static fn (): int => now()
                ->getTimestamp()
        );
        $this->provider = $this->incoming->commands;
        $key = $this->terms->subjectKey();
        $this->store = MachineStore::configure($this->app, new Authority(
            DB::connection(),
            'provider-worker',
            [$key],
            [
                $key => FundingCatalogue::BANK,
            ],
            [$key]
        ), $this->provider, $this->incoming);
    }

    private function race(string $winner, string $loser, string $runId, array $binding): array
    {
        $root = sys_get_temp_dir() . '/dw-cart-association-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        DB::purge();
        $children = [];
        try {
            foreach ([$winner, $loser] as $index => $operation) {
                $children[] = $this->forkContender($root, $index, $operation, $runId, $binding);
            }
            self::waitFile($root . '/held');
            self::waitFile($root . '/backend-1');
            $backend = (int) file_get_contents($root . '/backend-1');
            DB::reconnect();
            $this->awaitRootLock($backend);
            touch($root . '/release');
            return $this->collectResults($root, $children);
        } finally {
            $this->cleanupRace($root, $children);
            $this->reconnectCart();
        }
    }

    private function forkContender(string $root, int $index, string $operation, string $runId, array $binding): int
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Cannot fork the Cart association race.');
        }
        if ($pid === 0) {
            exit($this->runContender($root, $index, $operation, $runId, $binding));
        }
        return $pid;
    }

    private function runContender(string $root, int $index, string $operation, string $runId, array $binding): int
    {
        try {
            $this->reconnectCart();
            DB::statement("SET lock_timeout = '15s'");
            file_put_contents(
                $root . '/backend-' . $index,
                (string) DB::selectOne('SELECT pg_backend_pid() AS id')->id
            );
            if ($index === 0) {
                $this->holdWinningMutation($root, $operation);
            } else {
                self::waitFile($root . '/held');
            }
            file_put_contents($root . '/' . $index . '.json', json_encode([
                'result' => $this->operate($operation, $runId, $binding),
            ], JSON_THROW_ON_ERROR));
            return 0;
        } catch (Throwable $error) {
            file_put_contents($root . '/' . $index . '.json', json_encode([
                'error' => $error::class . ': ' . $error->getMessage(),
            ], JSON_THROW_ON_ERROR));
            return 1;
        } finally {
            DB::disconnect();
        }
    }

    private function holdWinningMutation(string $root, string $operation): void
    {
        $held = false;
        DB::listen(static function (QueryExecuted $query) use ($root, $operation, &$held): void {
            $sql = strtolower($query->sql);
            $mutation = $operation === 'cancel'
                ? str_starts_with($sql, 'update') && str_contains($sql, 'example_machine_subjects')
                : str_starts_with($sql, 'insert') && str_contains($sql, 'provider_cart_orders');
            if (! $held && $mutation) {
                if ($query->connection->transactionLevel() !== 1) {
                    throw new RuntimeException('The winning Cart mutation was not in its owned transaction.');
                }
                $held = true;
                touch($root . '/held');
                self::waitFile($root . '/release');
            }
        });
    }

    private function awaitRootLock(int $backend): void
    {
        $deadline = microtime(true) + 5;
        $waiting = false;
        while (! $waiting && microtime(true) < $deadline) {
            $row = DB::selectOne('SELECT query, wait_event_type FROM pg_stat_activity WHERE pid = ?', [$backend]);
            $sql = strtolower((string) ($row->query ?? ''));
            $waiting = ($row->wait_event_type ?? null) === 'Lock' && str_starts_with($sql, 'select')
                && str_contains($sql, 'example_machine_subjects') && str_contains($sql, 'for update');
            if (! $waiting) {
                usleep(10_000);
            }
        }
        $this->assertTrue($waiting, 'The competing original purchase row lock was not observed.');
    }

    private function collectResults(string $root, array $children): array
    {
        $results = [];
        foreach ($children as $index => $pid) {
            pcntl_waitpid($pid, $status);
            $payload = json_decode(file_get_contents($root . '/' . $index . '.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status), $payload['error'] ?? 'Cart contender failed.');
            $this->assertArrayHasKey('result', $payload);
            $results[] = $payload['result'];
        }
        return $results;
    }

    private function cleanupRace(string $root, array $children): void
    {
        touch($root . '/release');
        foreach ($children as $pid) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                posix_kill($pid, SIGKILL);
                pcntl_waitpid($pid, $status);
            }
        }
        foreach (glob($root . '/*') as $file) {
            unlink($file);
        }
        rmdir($root);
    }

    private static function waitFile(string $path): void
    {
        $deadline = microtime(true) + 10;
        while (! is_file($path)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The Cart association barrier timed out.');
            }
            usleep(10_000);
        }
    }
}
