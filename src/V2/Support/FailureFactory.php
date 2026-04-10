<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Error;
use Exception;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Throwable;
use Workflow\Serializers\Serializer;
use Workflow\V2\Exceptions\RestoredWorkflowException;

final class FailureFactory
{
    private const MAX_TRACE_FRAMES = 20;

    /**
     * @return array{
     *     exception_class: class-string<Throwable>,
     *     message: string,
     *     file: string,
     *     line: int,
     *     trace_preview: string
     * }
     */
    public static function make(Throwable $throwable): array
    {
        $payload = self::payload($throwable);

        return [
            'exception_class' => is_string($payload['class'] ?? null)
                ? $payload['class']
                : $throwable::class,
            'message' => is_string($payload['message'] ?? null)
                ? $payload['message']
                : $throwable->getMessage(),
            'file' => is_string($payload['file'] ?? null)
                ? $payload['file']
                : $throwable->getFile(),
            'line' => is_int($payload['line'] ?? null)
                ? $payload['line']
                : $throwable->getLine(),
            'trace_preview' => self::previewFromPayload($payload),
        ];
    }

    /**
     * @return array{
     *     class: class-string<Throwable>|string,
     *     type?: string,
     *     message: string,
     *     code: int,
     *     file: string,
     *     line: int,
     *     trace: list<array<string, mixed>>,
     *     properties: list<array{declaring_class: string, name: string, value: mixed}>
     * }
     */
    public static function payload(Throwable $throwable): array
    {
        if ($throwable instanceof RestoredWorkflowException) {
            /** @var array{
             *     class: class-string<Throwable>|string,
             *     type?: string,
             *     message: string,
             *     code: int,
             *     file: string,
             *     line: int,
             *     trace: list<array<string, mixed>>,
             *     properties: list<array{declaring_class: string, name: string, value: mixed}>
             * } $payload
             */
            $payload = $throwable->failurePayload();

            return $payload;
        }

        $payload = [
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
            'code' => $throwable->getCode(),
            'file' => $throwable->getFile(),
            'line' => $throwable->getLine(),
            'trace' => self::normalizeTrace($throwable),
            'properties' => self::normalizeProperties($throwable),
        ];

        $type = TypeRegistry::typeForThrowable($throwable::class);

        if (is_string($type) && $type !== '') {
            $payload['type'] = $type;
        }

        return $payload;
    }

    public static function restore(
        mixed $payload,
        ?string $fallbackClass = null,
        ?string $fallbackMessage = null,
        ?int $fallbackCode = null,
    ): Throwable {
        $normalized = self::normalizePayload($payload, $fallbackClass, $fallbackMessage, $fallbackCode);
        $class = $normalized['class'];

        try {
            $resolvedClass = is_string($class)
                ? TypeRegistry::resolveThrowableClass($class, $normalized['type'])
                : null;
        } catch (Throwable) {
            $resolvedClass = is_string($class) && class_exists($class) && is_subclass_of($class, Throwable::class)
                ? $class
                : null;
        }

        if (is_string($resolvedClass)) {
            try {
                return self::restoreThrowable($resolvedClass, $normalized);
            } catch (Throwable) {
                // Fall back to a typed wrapper when the original class cannot be rehydrated safely.
            }
        }

        return new RestoredWorkflowException($normalized);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function previewFromPayload(array $payload): string
    {
        $lines = [];

        foreach (array_slice(self::traceFrames($payload), 0, 5) as $frame) {
            $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : '';
            $type = isset($frame['type']) && is_string($frame['type']) ? $frame['type'] : '';
            $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : 'unknown';
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : 'unknown';
            $line = isset($frame['line']) ? (string) $frame['line'] : '0';

            $lines[] = sprintf('%s%s%s @ %s:%s', $class, $type, $function, $file, $line);
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function normalizeTrace(Throwable $throwable): array
    {
        $frames = [];

        foreach (array_slice($throwable->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            $normalized = array_filter([
                'class' => is_string($frame['class'] ?? null) ? $frame['class'] : null,
                'type' => is_string($frame['type'] ?? null) ? $frame['type'] : null,
                'function' => is_string($frame['function'] ?? null) ? $frame['function'] : null,
                'file' => is_string($frame['file'] ?? null) ? $frame['file'] : null,
                'line' => is_int($frame['line'] ?? null) ? $frame['line'] : null,
            ], static fn (mixed $value): bool => $value !== null);

            if ($normalized !== []) {
                $frames[] = $normalized;
            }
        }

        return $frames;
    }

    /**
     * @return list<array{declaring_class: string, name: string, value: mixed}>
     */
    private static function normalizeProperties(Throwable $throwable): array
    {
        $properties = [];
        $reflection = new ReflectionClass($throwable);

        while ($reflection->getName() !== Exception::class && $reflection->getName() !== Error::class) {
            if (! $reflection->isInternal()) {
                foreach ($reflection->getProperties() as $property) {
                    if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $reflection->getName()) {
                        continue;
                    }

                    if (! $property->isInitialized($throwable)) {
                        continue;
                    }

                    $value = Serializer::serializeModels($property->getValue($throwable));

                    if (! Serializer::serializable($value)) {
                        continue;
                    }

                    $properties[] = [
                        'declaring_class' => $reflection->getName(),
                        'name' => $property->getName(),
                        'value' => $value,
                    ];
                }
            }

            $reflection = $reflection->getParentClass();

            if ($reflection === false) {
                break;
            }
        }

        return $properties;
    }

    /**
     * @return array{
     *     class: class-string<Throwable>|string,
     *     type: string|null,
     *     message: string,
     *     code: int,
     *     file: string,
     *     line: int,
     *     trace: list<array<string, mixed>>,
     *     properties: list<array{declaring_class: string, name: string, value: mixed}>
     * }
     */
    private static function normalizePayload(
        mixed $payload,
        ?string $fallbackClass,
        ?string $fallbackMessage,
        ?int $fallbackCode,
    ): array {
        if (is_string($payload)) {
            $payload = Serializer::unserialize($payload);
        }

        if (! is_array($payload)) {
            $payload = [];
        }

        return [
            'class' => is_string($payload['class'] ?? null)
                ? $payload['class']
                : ($fallbackClass ?? RestoredWorkflowException::class),
            'type' => is_string($payload['type'] ?? null)
                ? $payload['type']
                : null,
            'message' => is_string($payload['message'] ?? null)
                ? $payload['message']
                : ($fallbackMessage ?? 'Workflow failure'),
            'code' => is_int($payload['code'] ?? null)
                ? $payload['code']
                : ($fallbackCode ?? 0),
            'file' => is_string($payload['file'] ?? null)
                ? $payload['file']
                : __FILE__,
            'line' => is_int($payload['line'] ?? null)
                ? $payload['line']
                : __LINE__,
            'trace' => self::traceFrames($payload),
            'properties' => self::propertyFrames($payload),
        ];
    }

    /**
     * @param array{
     *     class: class-string<Throwable>|string,
     *     type?: string|null,
     *     message: string,
     *     code: int,
     *     file: string,
     *     line: int,
     *     trace: list<array<string, mixed>>,
     *     properties: list<array{declaring_class: string, name: string, value: mixed}>
     * } $payload
     */
    private static function restoreThrowable(string $class, array $payload): Throwable
    {
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            throw new ReflectionException(sprintf('Cannot restore abstract throwable [%s].', $class));
        }

        /** @var Throwable $throwable */
        $throwable = $reflection->newInstanceWithoutConstructor();
        $baseClass = is_subclass_of($class, Error::class) ? Error::class : Exception::class;

        self::setThrowableProperty($throwable, $baseClass, 'message', $payload['message']);
        self::setThrowableProperty($throwable, $baseClass, 'code', $payload['code']);
        self::setThrowableProperty($throwable, $baseClass, 'file', $payload['file']);
        self::setThrowableProperty($throwable, $baseClass, 'line', $payload['line']);
        self::setThrowableProperty($throwable, $baseClass, 'trace', $payload['trace']);

        foreach ($payload['properties'] as $property) {
            if (! is_string($property['declaring_class'] ?? null) || ! is_string($property['name'] ?? null)) {
                continue;
            }

            $value = array_key_exists('value', $property)
                ? Serializer::unserializeModels($property['value'])
                : null;

            self::setPayloadProperty(
                $throwable,
                $class,
                $property['declaring_class'],
                $property['name'],
                $value,
            );
        }

        return $throwable;
    }

    private static function setThrowableProperty(
        Throwable $throwable,
        string $class,
        string $property,
        mixed $value,
    ): void {
        $reflectionProperty = new ReflectionProperty($class, $property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($throwable, $value);
    }

    private static function setPayloadProperty(
        Throwable $throwable,
        string $restoredClass,
        string $declaringClass,
        string $property,
        mixed $value,
    ): void {
        $candidates = array_values(array_unique([
            $declaringClass,
            $restoredClass,
        ]));

        foreach ($candidates as $candidate) {
            if (! class_exists($candidate)) {
                continue;
            }

            if ($candidate !== $restoredClass && ! is_a($restoredClass, $candidate, true)) {
                continue;
            }

            try {
                self::setThrowableProperty($throwable, $candidate, $property, $value);

                return;
            } catch (Throwable) {
                // A renamed exception should still be catchable even if one custom field no longer exists.
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private static function traceFrames(array $payload): array
    {
        $trace = $payload['trace'] ?? null;

        if (! is_array($trace)) {
            return [];
        }

        return array_values(array_filter($trace, static fn (mixed $frame): bool => is_array($frame)));
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{declaring_class: string, name: string, value: mixed}>
     */
    private static function propertyFrames(array $payload): array
    {
        $properties = $payload['properties'] ?? null;

        if (! is_array($properties)) {
            return [];
        }

        return array_values(array_filter($properties, static fn (mixed $property): bool => is_array($property)));
    }
}
