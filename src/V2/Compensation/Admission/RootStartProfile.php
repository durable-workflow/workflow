<?php

declare(strict_types=1);

namespace Workflow\V2\Compensation\Admission;

use InvalidArgumentException;
use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Enums\DuplicateStartPolicy;
use Workflow\V2\StartOptions;

/** @experimental Frozen native routing and start options, not admission authority. */
final readonly class RootStartProfile
{
    public function __construct(
        public string $connection,
        public string $queue,
        public string $codec,
        public StartOptions $options = new StartOptions(),
        public ?string $compatibility = null,
    ) {
        foreach ([$connection, $queue, $codec] as $value) {
            if (trim($value) === '' || strlen($value) > 512) {
                throw new InvalidArgumentException('Root routing and codec require bounded nonempty names.');
            }
        }
        if ($compatibility !== null && (trim($compatibility) === '' || trim($compatibility) !== $compatibility
            || strlen($compatibility) > 512)) {
            throw new InvalidArgumentException('Root worker compatibility must already be canonical and bounded.');
        }
        PortableDescriptor::encode($this->toArray());
    }

    public function toArray(): array
    {
        return [
            'version' => 1,
            'connection' => $this->connection,
            'queue' => $this->queue,
            'codec' => $this->codec,
            'compatibility' => $this->compatibility,
            'options' => [
                'duplicateStartPolicy' => $this->options->duplicateStartPolicy->value,
                'businessKey' => $this->options->businessKey,
                'labels' => $this->options->labels,
                'memo' => $this->options->memo,
                'searchAttributes' => $this->options->searchAttributes,
                'executionTimeoutSeconds' => $this->options->executionTimeoutSeconds,
                'runTimeoutSeconds' => $this->options->runTimeoutSeconds,
                'timeoutCleanupSeconds' => $this->options->timeoutCleanupSeconds,
                'priority' => $this->options->priority,
                'fairnessKey' => $this->options->fairnessKey,
                'fairnessWeight' => $this->options->fairnessWeight,
            ],
        ];
    }

    public static function fromArray(array $value): self
    {
        RootFormat::keys($value, ['version', 'connection', 'queue', 'codec', 'compatibility', 'options']);
        if ($value['version'] !== 1 || ! is_array($value['options'])) {
            throw new InvalidArgumentException('Unsupported root start profile.');
        }
        RootFormat::strings($value, ['connection', 'queue', 'codec'], ['compatibility']);
        $options = $value['options'];
        RootFormat::keys($options, ['duplicateStartPolicy', 'businessKey', 'labels', 'memo', 'searchAttributes',
            'executionTimeoutSeconds', 'runTimeoutSeconds', 'timeoutCleanupSeconds', 'priority', 'fairnessKey',
            'fairnessWeight']);
        RootFormat::strings($options, ['duplicateStartPolicy'], ['businessKey', 'fairnessKey']);
        foreach (['labels', 'memo', 'searchAttributes'] as $key) {
            if (! is_array($options[$key])) {
                throw new InvalidArgumentException('Root visibility metadata must be arrays.');
            }
        }
        foreach (['priority', 'fairnessWeight'] as $key) {
            if (! is_int($options[$key])) {
                throw new InvalidArgumentException('Root scheduling values must be integers.');
            }
        }
        foreach (['executionTimeoutSeconds', 'runTimeoutSeconds', 'timeoutCleanupSeconds'] as $key) {
            if ($options[$key] !== null && ! is_int($options[$key])) {
                throw new InvalidArgumentException('Root timeout values must be nullable integers.');
            }
        }
        $duplicate = DuplicateStartPolicy::tryFrom($options['duplicateStartPolicy']);
        if ($duplicate === null) {
            throw new InvalidArgumentException('Unknown root duplicate-start policy.');
        }
        $profile = new self($value['connection'], $value['queue'], $value['codec'], new StartOptions(
            duplicateStartPolicy: $duplicate,
            businessKey: $options['businessKey'],
            labels: $options['labels'],
            memo: $options['memo'],
            searchAttributes: $options['searchAttributes'],
            executionTimeoutSeconds: $options['executionTimeoutSeconds'],
            runTimeoutSeconds: $options['runTimeoutSeconds'],
            priority: $options['priority'],
            fairnessKey: $options['fairnessKey'],
            fairnessWeight: $options['fairnessWeight'],
            timeoutCleanupSeconds: $options['timeoutCleanupSeconds'],
        ), $value['compatibility']);
        if (PortableDescriptor::encode($profile->toArray()) !== PortableDescriptor::encode($value)) {
            throw new InvalidArgumentException('Stored root options must already be canonical.');
        }
        return $profile;
    }
}
