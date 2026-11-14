<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Definition;

use InvalidArgumentException;

/**
 * @experimental The Compensation API is not covered by stability guarantees and may change in a minor release.
 */
final readonly class Obligation
{
    public function __construct(
        public string $key,
        public string $handlerType,
        public string $handlerVersion,
        public array $input,
    ) {
        PortableDescriptor::identifier($key, 'operation key');
        PortableDescriptor::identifier($handlerVersion, 'handler version');
        if (! preg_match('/\A[a-z][a-z0-9._-]{0,127}\z/D', $handlerType)) {
            throw new InvalidArgumentException(
                'Compensation handlers require a registered type, not a class or callable.'
            );
        }
        PortableDescriptor::encode($this->toArray());
    }

    public function fingerprint(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'key' => $this->key,
            'handlerType' => $this->handlerType,
            'handlerVersion' => $this->handlerVersion,
            'input' => $this->input,
        ];
    }

    public static function fromStored(array $row): self
    {
        $value = self::fromArray(json_decode($row['descriptor'], true, flags: JSON_THROW_ON_ERROR));
        if (! hash_equals($row['fingerprint'], $value->fingerprint())) {
            throw new \LogicException('The retained compensation descriptor content hash does not match.');
        }
        return $value;
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'handlerType',
            'handlerVersion',
            'input',
            'key',
            'version',
        ] || ($value['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported compensation obligation format.');
        }
        return new self($value['key'], $value['handlerType'], $value['handlerVersion'], $value['input']);
    }
}
