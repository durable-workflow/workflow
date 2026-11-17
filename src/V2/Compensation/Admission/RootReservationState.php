<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Admission;

use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Support\ConfiguredV2Models;

/** @internal Closed root-fence decoding. Historical classes stay data, never autoload targets. */
final class RootReservationState
{
    public static function assertPolicies(RootReservation $reservation, string $version, string $scopeVersion): void
    {
        if ($version !== $reservation->authorizationVersion || $scopeVersion !== $reservation->scopeAuthorizationVersion) {
            throw new LogicException('The original root authorization policies must remain pinned.');
        }
    }

    public static function matching(WorkflowInstance $root, RootReservation $reservation): array
    {
        $state = self::readOwned($root, $reservation->identity);
        if (PortableDescriptor::encode($state['reservation']) !== PortableDescriptor::encode($reservation->toArray())) {
            throw new LogicException('The root descriptor differs from its original reservation.');
        }
        return $state;
    }

    public static function readOwned(WorkflowInstance $root, RootIdentity $identity): array
    {
        // Owner check precedes decoding protected JSON or resolving any stored class.
        if ($root->id !== $identity->instanceId() || $root->namespace !== $identity->scope->namespace
            || $root->workflow_class !== $identity->workflowClass || $root->workflow_type !== $identity->workflowType) {
            throw new LogicException('The native root reservation does not match the original owner.');
        }
        $raw = $root->getRawOriginal('native_root_reservation');
        if (! is_string($raw) || strlen($raw) > 65_536) {
            throw new LogicException('The native root has no valid reservation record.');
        }
        $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($state)) {
            throw new LogicException('Malformed native root reservation state.');
        }
        RootFormat::keys($state, ['version', 'reservation', 'status', 'admission', 'closureId']);
        if ($state['version'] !== 1 || ! is_array($state['reservation'])
            || ! in_array($state['status'], ['reserved', 'admitted', 'closed'], true)) {
            throw new LogicException('Malformed native root reservation state.');
        }
        $reservation = RootReservation::fromArray($state['reservation']);
        if ($reservation->identity->fingerprint() !== $identity->fingerprint()) {
            throw new LogicException('The stored root has a different immutable descriptor.');
        }
        $state['reservation'] = $reservation->toArray();
        return self::canonicalOutcome($state, $reservation);
    }

    public static function encode(array $state): string
    {
        // Match descriptor numeric/Unicode semantics. Default JSON encoding
        // collapses 1.0 to 1 and can triple valid UTF-8 receipt byte counts.
        $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($encoded) > 65_536) {
            throw new LogicException('The native root reservation exceeds its retained byte bound.');
        }
        return $encoded;
    }

    public static function write(WorkflowInstance $root, array $state): void
    {
        $encoded = self::encode($state);
        ConfiguredV2Models::query('instance_model', WorkflowInstance::class)->whereKey($root->id)->toBase()->update([
            'native_root_reservation' => $encoded,
        ]);
        $root->setRawAttributes([
            ...$root->getAttributes(),
            'native_root_reservation' => $encoded,
        ], true);
    }

    private static function canonicalOutcome(array $state, RootReservation $reservation): array
    {
        if ($state['status'] === 'admitted') {
            if (! is_array($state['admission']) || $state['closureId'] !== null) {
                throw new LogicException('Malformed native root admission state.');
            }
            $binding = RootBinding::fromArray($state['admission']);
            if ($binding->reservation->fingerprint() !== $reservation->fingerprint()) {
                throw new LogicException('The root receipt belongs to a different reservation.');
            }
            $state['admission'] = $binding->toArray();
        } elseif ($state['admission'] !== null || ($state['status'] === 'reserved' ? $state['closureId'] !== null
            : ! is_string($state['closureId']) || trim($state['closureId']) === '')) {
            throw new LogicException('Malformed native root closure state.');
        }
        return $state;
    }
}
