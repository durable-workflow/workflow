<?php

declare(strict_types=1);

namespace Workflow\V2;

use UnexpectedValueException;

/** The acknowledged checkpoint received from an earlier activity attempt. */
final readonly class ActivityHeartbeatCheckpoint
{
    public const VERSION = 1;

    /**
     * @param array<array-key, mixed> $details
     */
    private function __construct(
        private array $details,
        public string $acknowledgedAt,
        public int $contractVersion,
    ) {
    }

    /**
     * @return array<array-key, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @internal
     */
    public static function fromStorage(string $document): self
    {
        $stored = json_decode($document, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($stored) || ($stored['version'] ?? null) !== self::VERSION
            || ! is_string($stored['acknowledged_at'] ?? null)
            || ! is_string($stored['details_json'] ?? null)) {
            throw new UnexpectedValueException('The stored activity checkpoint is invalid.');
        }

        $details = json_decode($stored['details_json'], true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($details)) {
            throw new UnexpectedValueException('The stored activity checkpoint details are invalid.');
        }

        return new self($details, $stored['acknowledged_at'], self::VERSION);
    }
}
