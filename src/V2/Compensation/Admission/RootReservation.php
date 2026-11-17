<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Admission;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;

/** @experimental Retained original intent. This value alone proves no admission or settlement. */
final readonly class RootReservation
{
    public function __construct(
        public RootIdentity $identity,
        public string $originalRunId,
        public string $authorizationVersion,
        public string $scopeAuthorizationVersion,
    ) {
        PortableDescriptor::identifier($originalRunId, 'reserved original root run');
        foreach ([$authorizationVersion, $scopeAuthorizationVersion] as $version) {
            if (trim($version) === '' || strlen($version) > 128) {
                throw new InvalidArgumentException('A root reservation requires its original authorization policies.');
            }
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
            'identity' => $this->identity->toArray(),
            'originalRunId' => $this->originalRunId,
            'authorizationVersion' => $this->authorizationVersion,
            'scopeAuthorizationVersion' => $this->scopeAuthorizationVersion,
        ];
    }

    public static function fromArray(array $value): self
    {
        RootFormat::keys($value, ['version', 'identity', 'originalRunId', 'authorizationVersion',
            'scopeAuthorizationVersion']);
        RootFormat::strings($value, ['originalRunId', 'authorizationVersion', 'scopeAuthorizationVersion']);
        if ($value['version'] !== 1 || ! is_array($value['identity'])) {
            throw new InvalidArgumentException('Unsupported root reservation.');
        }
        return new self(
            RootIdentity::fromArray($value['identity']),
            $value['originalRunId'],
            $value['authorizationVersion'],
            $value['scopeAuthorizationVersion'],
        );
    }
}
