<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Identity;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\Request;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentDescriptor;
use DurableWorkflow\Examples\ConfiguredStateMachines\Providers\ShipmentTerms;
use Illuminate\Database\ConnectionInterface;
use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

/** Shared record checks for post-delivery unit processes (returns, exchanges and transit incidents). */
final class PostDeliveryRecords
{
    /**
     * The exact original registered obligation of this native process, before any provider I/O.
     */
    public static function registered(
        string $process,
        ScopeIdentity $scope,
        Obligation $expected,
        string $run,
        ?string $operationId = null,
        bool $observed = false
    ): void {
        $ledger = (new ScopeRepository())->inspect($scope);
        $rows = array_values(array_filter($ledger['obligations'], static fn (array $row): bool =>
            ($row['descriptor']['key'] ?? null) === $expected->key));
        if ($ledger['original_run_id'] !== $run || count($rows) !== 1
            || ($operationId !== null && $rows[0]['id'] !== $operationId)
            || $rows[0]['fingerprint'] !== $expected->fingerprint()
            || Obligation::fromArray($rows[0]['descriptor'])->fingerprint() !== $expected->fingerprint()
            || ($observed && $rows[0]['forward_status'] !== 'observed')) {
            throw new LogicException("The {$process} operation requires its exact original registered obligation.");
        }
    }

    /**
     * @return array{string, string} The original Order key and the process key.
     */
    public static function parse(string $process, ScopeIdentity $scope, string $infix): array
    {
        $key = substr($scope->key, 0, 64);
        $suffix = substr($scope->key, 64);
        if (preg_match('/\A[0-9a-f]{64}\z/D', $key) !== 1 || ! str_starts_with($suffix, $infix)) {
            throw new LogicException("The scope is not an original unit {$process} scope.");
        }
        return [$key, substr($suffix, strlen($infix))];
    }

    public static function decode(string $process, string $payload, ?string $sha256): array
    {
        if ($sha256 === null || ! hash_equals($sha256, hash('sha256', $payload))) {
            throw new LogicException("The recorded original {$process} data changed.");
        }
        return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The recorded forward value of one process obligation, or null before it was observed.
     */
    public static function forward(ScopeIdentity $scope, string $key): ?array
    {
        foreach ((new ScopeRepository())->inspect($scope)['obligations'] as $row) {
            if (($row['descriptor']['key'] ?? null) === $key && $row['forward_status'] === 'observed') {
                return json_decode($row['forward_result'], true, flags: JSON_THROW_ON_ERROR)['value'];
            }
        }
        return null;
    }

    /**
     * Stores the first immutable record of one process; a later different value never replaces it.
     */
    public static function storeOnce(
        ConnectionInterface $db,
        string $table,
        string $id,
        string $column,
        string $payload
    ): void {
        $db->transaction(static function () use ($db, $table, $id, $column, $payload): void {
            $current = $db->table($table)
                ->where('id', $id)
                ->lockForUpdate()
                ->sole();
            if ($current->{$column} === null) {
                $db->table($table)
                    ->where('id', $id)
                    ->update([
                        $column => $payload,
                        $column . '_sha256' => hash('sha256', $payload),
                    ]);
            }
        });
    }

    /**
     * Replacement identities: same original owner, distinct process-scoped operations, never an original graph.
     *
     * @return array{reserve: Request, release: Request, graph: ShipmentDescriptor}
     */
    public static function replacement(Request $booking, string $prefix, string $sku, int $quantity): array
    {
        $identity = static fn (string $stage): Identity => new Identity(
            $booking->identity->client,
            $booking->identity->subject,
            $booking->identity->generation,
            $prefix . '.' . $stage
        );
        $original = ShipmentTerms::fromArray($booking->terms);
        $stock = [
            'sku' => $sku,
            'quantity' => $quantity,
        ];
        $terms = (new ShipmentTerms($sku, $quantity, $original->carrier))->toArray();
        $reserve = $identity('reserve');
        $book = $identity('book');
        $return = $identity('return');
        return [
            'reserve' => new Request($reserve, 'reserve', $stock),
            'release' => new Request($identity('release'), 'release', $stock, $reserve),
            'graph' => new ShipmentDescriptor(
                new Request($book, 'ship-book', $terms),
                new Request($identity('cancel'), 'ship-cancel-booking', $terms, $book),
                new Request($return, 'request-return', $terms, $book),
                new Request($identity('restock'), 'restock', $terms, $return),
            ),
        ];
    }

    /**
     * Recorded carrier progress of a replacement parcel; delivery or a failure ends the wait.
     */
    public static function carrierProgress(array $events): array
    {
        $failed = array_values(array_intersect(['damaged', 'lost', 'non-collection'], array_keys($events)));
        return [
            'action' => isset($events['delivered']) ? 'delivered' : ($failed === [] ? 'wait' : 'failed'),
            'handedOver' => isset($events['handed-over']),
            'delivered' => isset($events['delivered']),
            'failed' => $failed,
        ];
    }
}
