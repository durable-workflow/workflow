<?php

declare(strict_types=1);

namespace Workflow\V2\EntityReferences;

use InvalidArgumentException;

/**
 * A portable reference to a persisted domain entity (#996).
 *
 * On the wire it is an ordinary Avro map, `{type, id, version}`, so the value
 * schema is unchanged and arguments without references stay byte-identical.
 * The engine also records which argument positions hold references; the map
 * alone never triggers resolution.
 *
 * @experimental Draft API for workflow issue 996.
 */
final class EntityReference
{
    /**
     * @param non-empty-string $type Logical type registered in config.
     * @param non-empty-string $id
     * @param positive-int $version
     */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly int $version,
    ) {
        if ($type === '' || $id === '' || $version < 1) {
            throw new InvalidArgumentException('An entity reference needs a type, an identifier and a version >= 1.');
        }
    }

    /**
     * @return array{type: string, id: string, version: int}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'version' => $this->version,
        ];
    }

    /**
     * @param array<mixed> $value
     */
    public static function fromArray(array $value): self
    {
        if (count($value) !== 3
            || ! is_string($value['type'] ?? null) || ! is_string($value['id'] ?? null)
            || ! is_int($value['version'] ?? null)) {
            throw new InvalidArgumentException('A recorded entity reference must be exactly {type, id, version}.');
        }

        return new self($value['type'], $value['id'], $value['version']);
    }
}
