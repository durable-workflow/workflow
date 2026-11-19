<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use LogicException;
use Workflow\V2\Compensation\Definition\Obligation;
use Workflow\V2\Compensation\Definition\ScopeIdentity;
use Workflow\V2\Compensation\Persistence\ScopeRepository;

/** Shared record checks for post-delivery unit processes (returns and exchanges). */
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
}
