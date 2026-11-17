<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines\Providers;

use LogicException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** Closed decoding of a retained provider command, without claiming external settlement. */
final class UnitRefundState
{
    public static function command(array $row): UnitRefundCommand
    {
        $command = UnitRefundCommand::fromArray(self::decode($row['descriptor']));
        if ($row['id'] !== $command->id() || $row['fingerprint'] !== $command->fingerprint()
            || $row['descriptor'] !== PortableDescriptor::encode($command->toArray())) {
            throw new LogicException('The retained original unit refund command changed.');
        }
        return $command;
    }

    public static function selection(UnitRefundCommand $command, array $row): array
    {
        $status = Status::from($row['status']);
        $closed = in_array($status, [Status::Confirmed, Status::Refused, Status::NotExecuted], true);
        if (! in_array($row['closed'], [0, 1], true) || ! in_array($row['admitted'], [0, 1], true)
            || (bool) $row['closed'] !== $closed) {
            throw new LogicException('The original unit refund has inconsistent admission or closure.');
        }
        $claimed = self::decode($row['claimed']);
        $covered = self::decode($row['covered']);
        if (! array_is_list($claimed) || ! array_is_list($covered)
            || $row['claimed'] !== PortableDescriptor::encode($claimed)
            || $row['covered'] !== PortableDescriptor::encode($covered)) {
            throw new LogicException('The original unit refund selection is malformed.');
        }
        if ($claimed !== [] && $command->original->ordinals($claimed) !== $claimed) {
            throw new LogicException('The original claimed unit set is not canonical.');
        }
        $coveredUnits = self::coveredUnits($command, $covered);
        $units = [...$claimed, ...$coveredUnits];
        sort($units);
        if ($row['admitted'] === 1 ? $units !== $command->units : ($units !== [] || $status === Status::Confirmed)) {
            throw new LogicException('The original refund selection does not match its complete requested units.');
        }
        return [
            'claimed' => $claimed,
            'covered' => $covered,
        ];
    }

    private static function coveredUnits(UnitRefundCommand $command, array $covered): array
    {
        $units = [];
        foreach ($covered as $entry) {
            $keys = is_array($entry) ? array_keys($entry) : [];
            sort($keys);
            if (! is_array($entry) || $keys !== ['commandId', 'unit']
                || ! is_int($entry['unit']) || ! is_string($entry['commandId'])
                || preg_match('/\A[a-f0-9]{64}\z/D', $entry['commandId']) !== 1
                || $entry['commandId'] === $command->id()) {
                throw new LogicException('An original covered-unit receipt is malformed or self-referential.');
            }
            $units[] = $entry['unit'];
        }
        if ($units !== [] && $command->original->ordinals($units) !== $units) {
            throw new LogicException('The original covered unit set is not canonical.');
        }
        return $units;
    }

    private static function decode(string $encoded): array
    {
        if (strlen($encoded) > PortableDescriptor::MAX_BYTES) {
            throw new LogicException('The retained original unit refund exceeds its data bound.');
        }
        $value = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new LogicException('The retained original unit refund data is malformed.');
        }
        return $value;
    }
}
