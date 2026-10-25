<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use RuntimeException;
use Workflow\V2\Contracts\ExternalPayloadStorageDriver;
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Contracts\PredictableExternalPayloadStorageDriver;

/**
 * An in-memory external payload policy for completion budget tests: every
 * namespace stores payloads above the threshold through one driver that
 * records each put. A predictable driver derives its URI from the content and
 * can be told to fail its puts; an unpredictable one implements only the
 * original driver interface and returns a fresh URI from each put.
 */
final class RecordingExternalPayloadStorage implements ExternalPayloadStoragePolicy
{
    /**
     * @var list<array{sha256: string, codec: string}>
     */
    public array $puts = [];

    /**
     * @var array<string, string>
     */
    public array $objects = [];

    public bool $failPuts = false;

    private readonly ExternalPayloadStorageDriver $driver;

    public function __construct(
        bool $predictable = true,
        private readonly int $threshold = 16
    ) {
        $recorder = $this;
        $this->driver = $predictable
            ? new class($recorder) implements PredictableExternalPayloadStorageDriver {
                public function __construct(
                    private readonly RecordingExternalPayloadStorage $recorder
                ) {
                }

                public function uriFor(string $sha256, string $codec): string
                {
                    return "memory://{$codec}/{$sha256}";
                }

                public function put(string $data, string $sha256, string $codec): string
                {
                    if ($this->recorder->failPuts) {
                        throw new RuntimeException('put failed');
                    }

                    return $this->recorder->record($data, $sha256, $codec, $this->uriFor($sha256, $codec));
                }

                public function get(string $uri): string
                {
                    return $this->recorder->objects[$uri] ?? throw new RuntimeException("No payload at {$uri}.");
                }

                public function delete(string $uri): void
                {
                }
            }
            : new class($recorder) implements ExternalPayloadStorageDriver {
                public function __construct(
                    private readonly RecordingExternalPayloadStorage $recorder
                ) {
                }

                public function put(string $data, string $sha256, string $codec): string
                {
                    return $this->recorder->record(
                        $data,
                        $sha256,
                        $codec,
                        'memory://random/' . bin2hex(random_bytes(8))
                    );
                }

                public function get(string $uri): string
                {
                    return $this->recorder->objects[$uri] ?? throw new RuntimeException("No payload at {$uri}.");
                }

                public function delete(string $uri): void
                {
                }
            };
    }

    public function driverFor(?string $namespace): ?ExternalPayloadStorageDriver
    {
        return $this->driver;
    }

    public function thresholdBytesFor(?string $namespace): ?int
    {
        return $this->threshold;
    }

    public function record(string $data, string $sha256, string $codec, string $uri): string
    {
        $this->puts[] = [
            'sha256' => $sha256,
            'codec' => $codec,
        ];
        $this->objects[$uri] = $data;

        return $uri;
    }
}
