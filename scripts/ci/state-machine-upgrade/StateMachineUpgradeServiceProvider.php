<?php

declare(strict_types=1);

namespace App\Providers;

use DurableWorkflow\Examples\ConfiguredStateMachines\MachineStore as ConfiguredStore;
use DurableWorkflow\Examples\ConfiguredStateMachines\Ownership;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Journal;
use DurableWorkflow\Examples\ProviderStateMachines\Authority;
use DurableWorkflow\Examples\ProviderStateMachines\Catalogue;
use DurableWorkflow\Examples\ProviderStateMachines\MachineStore as ProviderStore;
use DurableWorkflow\Examples\ProviderStateMachines\OrderTerms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use PDO;

/**
 * Installs one copied state-machine example into this host application.
 *
 * STATE_MACHINE_EXAMPLE selects the example for this process. The examples
 * bind the same engine contracts (handler registry, scope authorization and
 * child definitions), so each one runs with its own database and queue.
 */
final class StateMachineUpgradeServiceProvider extends ServiceProvider
{
    public const PROVIDER = 'provider';

    public const CONFIGURED = 'configured';

    public const CONFIGURED_SUBJECT = 'case-aaaaaaaaaaaaaaaa-g1';

    /** Provider-backed checkout Orders, one per scenario. */
    public const PROVIDER_SCENARIOS = ['checkout', 'refund', 'late'];

    public static function example(): ?string
    {
        $example = env('STATE_MACHINE_EXAMPLE');

        return in_array($example, [self::PROVIDER, self::CONFIGURED], true) ? $example : null;
    }

    public static function terms(string $scenario): OrderTerms
    {
        return new OrderTerms('shop-a', 'purchase-' . $scenario, 'business-generation-1', 1499, 'USD', 'sku-1', 2);
    }

    public static function journalPath(): string
    {
        return storage_path('app/provider-journal.sqlite');
    }

    public static function journal(): Journal
    {
        $connection = new PDO('sqlite:' . self::journalPath());
        $connection->setAttribute(PDO::ATTR_TIMEOUT, 30);

        return new Journal($connection, 'shop-a');
    }

    public static function examplePath(string $example): string
    {
        return base_path('state-machine-examples/' . $example . '-state-machines');
    }

    public function register(): void
    {
        match (self::example()) {
            self::PROVIDER => $this->registerProvider(),
            self::CONFIGURED => $this->registerConfigured(),
            null => null,
        };
    }

    private function registerProvider(): void
    {
        require_once self::examplePath(self::PROVIDER) . '/bootstrap.php';
        config(['workflows.v2.namespaces' => [Catalogue::NAMESPACE]]);
        $this->app->booting(function (): void {
            $keys = array_map(
                static fn (string $scenario): string => self::terms($scenario)->subjectKey(),
                self::PROVIDER_SCENARIOS,
            );
            $authority = new Authority(
                DB::connection(),
                'provider-worker',
                $keys,
                array_fill_keys($keys, Catalogue::RESERVE_FIRST),
            );
            ProviderStore::configure($this->app, $authority, self::journal());
        });
    }

    private function registerConfigured(): void
    {
        require_once self::examplePath(self::CONFIGURED) . '/bootstrap.php';
        config(['workflows.v2.namespaces' => [Ownership::NAMESPACE]]);
        $this->app->booting(function (): void {
            ConfiguredStore::configure(
                $this->app,
                DB::connection(),
                'configured-example-worker',
                [self::CONFIGURED_SUBJECT],
            );
        });
    }
}
