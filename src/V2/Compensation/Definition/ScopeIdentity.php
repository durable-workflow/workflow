<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Definition;

use InvalidArgumentException;

/**
 * Identity of one compensation scope: `(application, namespace, key, generation)`.
 *
 * - application, key and generation: 1 to 128 characters from `A-Z a-z 0-9 . _ : / -`, starting with a
 *   letter or digit. Use a generation unique to the originating run.
 * - namespace: the native workflow namespace of the originating run, or null for the default
 *   namespace. Null is kept as null and never replaced by a configured alias.
 *
 * id() is the lowercase hex SHA-256 of the canonical JSON of toArray() (keys sorted, unescaped
 * Unicode). It names the scope in the ledger.
 *
 * @api Stable compensation-scope API (#1063). See docs/api-stability.md#compensation-scopes.
 */
final readonly class ScopeIdentity
{
    public function __construct(
        public string $application,
        public ?string $namespace,
        public string $key,
        public string $generation,
    ) {
        foreach ([
            'application' => $application,
            'namespace' => $namespace,
            'key' => $key,
            'generation' => $generation,
        ] as $name => $value) {
            if ($name === 'namespace' && $value === null) {
                continue;
            }
            PortableDescriptor::identifier($value, $name);
        }
    }

    public function id(): string
    {
        return hash('sha256', PortableDescriptor::encode($this->toArray()));
    }

    /**
     * @internal `dw-compensation-` followed by id(): the native instance of the canonical recovery workflow.
     */
    public function recoveryInstanceId(): string
    {
        return 'dw-compensation-' . $this->id();
    }

    /**
     * The portable version-1 form, `['version' => 1, 'application', 'namespace', 'key', 'generation']`.
     * Unlike the ledger envelope codecs, it is public and stable with this class.
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'application' => $this->application,
            'namespace' => $this->namespace,
            'key' => $this->key,
            'generation' => $this->generation,
        ];
    }

    /**
     * Accepts exactly the toArray() form.
     */
    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'application',
            'generation',
            'key',
            'namespace',
            'version',
        ] || ($value['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Unsupported compensation scope identity format.');
        }
        return new self($value['application'], $value['namespace'], $value['key'], $value['generation']);
    }
}
