<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use Closure;
use LogicException;
use PDO;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Full-amount authorization policy inside the provider's own transaction, never a DW transaction. */
final readonly class AuthorizationLedger
{
    public function __construct(
        private PDO $db,
        private Closure $clock
    ) {
    }

    public static function handles(Request $request): bool
    {
        return in_array($request->action, [
            'authorize', 'capture-authorized', 'void-authorization', 'refund-authorized',
        ], true);
    }

    public function install(): void
    {
        $this->assertTransaction();
        $this->db->exec('CREATE TABLE provider_authorization_links (
            operation_id TEXT PRIMARY KEY, authorization_id TEXT NOT NULL, kind TEXT NOT NULL,
            fingerprint TEXT NOT NULL, admitted_at INTEGER, expires_at INTEGER NOT NULL,
            UNIQUE (authorization_id, kind)
        )');
    }

    public function admit(Request $request, Status $planned): Status
    {
        $this->assertTransaction();
        [$authorization, $terms, $kind] = $this->original($request);
        $slot = $this->slot($authorization, $kind);
        if ($slot !== null && ($slot['operation_id'] !== $request->identity->key()
            || $slot['fingerprint'] !== $request->fingerprint())) {
            throw new LogicException('Another operation cannot replace the original financial idempotency identity.');
        }
        $now = ($this->clock)();
        if (! is_int($now) || $now < 0) {
            throw new LogicException('The provider clock must return a nonnegative integer Unix timestamp.');
        }
        $status = match ($kind) {
            'authorize' => $terms->canAdmitCaptureAt($now) && $this->slot($authorization, 'void') === null
                ? $planned : Status::Refused,
            'capture' => $terms->canAdmitCaptureAt($now) && $this->confirmed($authorization)
                && $this->slot($authorization, 'void') === null ? $planned : Status::Refused,
            'void' => $this->voidStatus($authorization, $planned),
            'refund' => $this->confirmed($this->target($request, 'capture-authorized')) ? $planned : Status::Refused,
            default => throw new LogicException('The financial operation is unavailable.'),
        };
        // A void intent permanently closes future capture admission even while an admitted capture reconciles.
        $admitted = in_array($status, [Status::Confirmed, Status::Processing, Status::Unknown], true);
        $this->query('INSERT INTO provider_authorization_links
            (operation_id, authorization_id, kind, fingerprint, admitted_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(operation_id) DO UPDATE SET admitted_at = excluded.admitted_at', [
            $request->identity->key(), $authorization->identity->key(), $kind, $request->fingerprint(),
            $admitted ? $now : null, $terms->expiresAt,
        ]);
        return $status;
    }

    public function assertCompletion(Request $request): void
    {
        $this->assertTransaction();
        [$authorization, $terms, $kind] = $this->original($request);
        $this->assertAdmission($request, $authorization, $terms, $kind);
        $allowed = match ($kind) {
            'authorize' => true,
            // Current time and a pending void do not erase a capture already admitted before expiry.
            'capture' => $this->confirmed($authorization) && ! $this->confirmedSlot($authorization, 'void'),
            'void' => $this->voidStatus($authorization, Status::Confirmed) === Status::Confirmed,
            'refund' => $this->confirmed($this->target($request, 'capture-authorized')),
            default => false,
        };
        if (! $allowed) {
            throw new LogicException('The original financial outcome is not ready for this completion.');
        }
    }

    /**
     * @return array{Request, AuthorizationTerms, string}
     */
    private function original(Request $request): array
    {
        $kind = match ($request->action) {
            'authorize' => 'authorize',
            'capture-authorized' => 'capture',
            'void-authorization' => 'void',
            'refund-authorized' => 'refund',
            default => throw new LogicException('The financial operation is unavailable.'),
        };
        $authorization = match ($kind) {
            'authorize' => $request,
            'capture', 'void' => $this->target($request, 'authorize'),
            'refund' => $this->target($this->target($request, 'capture-authorized'), 'authorize'),
        };
        $terms = AuthorizationTerms::fromArray($authorization->terms);
        $expected = $kind === 'refund' ? $terms->captureTerms() : $terms->toArray();
        if ($request->terms !== PortableDescriptor::snapshot($expected)) {
            throw new LogicException('The financial command changes the original amount, currency or expiry.');
        }
        return [$authorization, $terms, $kind];
    }

    private function voidStatus(Request $authorization, Status $planned): Status
    {
        if (! $this->confirmed($authorization)) {
            return Status::Unknown;
        }
        $capture = $this->slot($authorization, 'capture');
        if ($capture === null) {
            return $planned;
        }
        $row = $this->row($capture['operation_id']);
        if ($row === null) {
            throw new LogicException('A financial admission lost its original provider outcome.');
        }
        return match (Status::from($row['status'])) {
            Status::Confirmed => Status::Refused,
            Status::Processing, Status::Unknown => Status::Unknown,
            Status::NotExecuted, Status::Refused => $planned,
        };
    }

    private function target(Request $request, string $action): Request
    {
        $row = $request->target === null ? null : $this->row($request->target->key());
        if ($row === null) {
            throw new LogicException('The original financial target is unavailable; lookup absence is not settlement.');
        }
        $target = Request::fromArray(json_decode($row['request'], true, flags: JSON_THROW_ON_ERROR));
        if ($target->action !== $action || $target->identity->toArray() !== $request->target->toArray()
            || ! $target->identity->sameOwner($request->identity)) {
            throw new LogicException('The financial command targets another original operation or owner.');
        }
        return $target;
    }

    private function confirmed(Request $request): bool
    {
        $row = $this->row($request->identity->key());
        if ($row === null || $row['fingerprint'] !== $request->fingerprint()) {
            throw new LogicException('The original financial command content is unavailable or substituted.');
        }
        if ($row['status'] !== Status::Confirmed->value) {
            return false;
        }
        [$authorization, $terms, $kind] = $this->original($request);
        $this->assertAdmission($request, $authorization, $terms, $kind);
        $effect = $this->query('SELECT effect_id FROM provider_effects WHERE operation_id = ?', [
            $request->identity->key(),
        ])->fetchColumn();
        if ($effect !== 'effect-' . $request->identity->key()) {
            throw new LogicException('A financial confirmation requires its original provider effect receipt.');
        }
        return true;
    }

    private function confirmedSlot(Request $authorization, string $kind): bool
    {
        $slot = $this->slot($authorization, $kind);
        if ($slot === null) {
            return false;
        }
        $row = $this->row($slot['operation_id']);
        if ($row === null) {
            throw new LogicException('A financial admission lost its original provider outcome.');
        }
        return $this->confirmed(Request::fromArray(json_decode($row['request'], true, flags: JSON_THROW_ON_ERROR)));
    }

    private function slot(Request $authorization, string $kind): ?array
    {
        $row = $this->query('SELECT * FROM provider_authorization_links WHERE authorization_id = ? AND kind = ?', [
            $authorization->identity->key(), $kind,
        ])->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function row(string $id): ?array
    {
        $row = $this->query('SELECT * FROM provider_operations WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && ! hash_equals($row['fingerprint'], hash('sha256', $row['request']))) {
            throw new LogicException('The original financial command content was substituted.');
        }
        return $row === false ? null : $row;
    }

    private function query(string $sql, array $bindings): \PDOStatement
    {
        $query = $this->db->prepare($sql);
        $query->execute($bindings);
        return $query;
    }

    private function assertAdmission(
        Request $request,
        Request $authorization,
        AuthorizationTerms $terms,
        string $kind
    ): void {
        $slot = $this->slot($authorization, $kind);
        if ($slot === null || $slot['operation_id'] !== $request->identity->key()
            || $slot['fingerprint'] !== $request->fingerprint() || ! is_int($slot['admitted_at'])
            || $slot['admitted_at'] < 0 || $slot['expires_at'] !== $terms->expiresAt) {
            throw new LogicException('Completion requires the original persisted financial admission.');
        }
        if (in_array($kind, ['authorize', 'capture'], true) && ! $terms->canAdmitCaptureAt($slot['admitted_at'])) {
            throw new LogicException('An expired authorization cannot manufacture a prior capture admission.');
        }
    }

    private function assertTransaction(): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' || ! $this->db->inTransaction()) {
            throw new LogicException('Financial admission belongs inside the synthetic provider transaction.');
        }
    }
}
