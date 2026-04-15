<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Error;
use Exception;
use Illuminate\Database\QueryException;
use Illuminate\Queue\MaxAttemptsExceededException;
use PDOException;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Throwable;
use Workflow\Exceptions\NonRetryableExceptionContract;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\FailureCategory;
use Workflow\V2\Exceptions\RestoredWorkflowException;
use Workflow\V2\Exceptions\StraightLineWorkflowRequiredException;
use Workflow\V2\Exceptions\StructuralLimitExceededException;
use Workflow\V2\Exceptions\UnresolvedWorkflowFailureException;
use Workflow\V2\Exceptions\UnsupportedWorkflowYieldException;

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
     * Classify a failure into the canonical taxonomy based on its propagation
     * kind, source kind, and the throwable itself.
     */
    public static function classify(
        string $propagationKind,
        string $sourceKind,
        ?Throwable $throwable = null
    ): FailureCategory {
        return match ($propagationKind) {
            'activity' => FailureCategory::Activity,
            'child' => FailureCategory::ChildWorkflow,
            'cancelled' => FailureCategory::Cancelled,
            'terminated' => FailureCategory::Terminated,
            'terminal', 'update' => self::classifyFromThrowable($throwable),
            default => FailureCategory::Application,
        };
    }

    /**
     * Classify a failure from string metadata (class name and message) without
     * requiring a live Throwable instance. Used by the external worker bridge
     * where the original throwable is not available.
     */
    public static function classifyFromStrings(
        string $propagationKind,
        string $sourceKind,
        ?string $exceptionClass,
        ?string $message
    ): FailureCategory {
        return match ($propagationKind) {
            'activity' => FailureCategory::Activity,
            'child' => FailureCategory::ChildWorkflow,
            'cancelled' => FailureCategory::Cancelled,
            'terminated' => FailureCategory::Terminated,
            'terminal', 'update' => self::classifyFromExceptionStrings($exceptionClass, $message),
            default => FailureCategory::Application,
        };
    }

    /**
     * Determine whether a failure should be marked as non-retryable. A
     * non-retryable failure will never be automatically retried by the engine,
     * regardless of the retry policy.
     */
    public static function isNonRetryable(?Throwable $throwable): bool
    {
        return $throwable instanceof NonRetryableExceptionContract;
    }

    /**
     * Determine non-retryable status from a recorded exception class string.
     * Used by the external worker bridge and backfill commands where the
     * original throwable is not available.
     */
    public static function isNonRetryableFromStrings(?string $exceptionClass): bool
    {
        if ($exceptionClass === null || $exceptionClass === '') {
            return false;
        }

        if (! class_exists($exceptionClass)) {
            return false;
        }

        return is_a($exceptionClass, NonRetryableExceptionContract::class, true);
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

    public static function restoreForReplay(
        mixed $payload,
        ?string $fallbackClass = null,
        ?string $fallbackMessage = null,
        ?int $fallbackCode = null,
    ): Throwable {
        $normalized = self::normalizePayload($payload, $fallbackClass, $fallbackMessage, $fallbackCode);
        $class = $normalized['class'];

        try {
            $resolution = is_string($class)
                ? TypeRegistry::resolveThrowableClassWithSource($class, $normalized['type'])
                : null;
        } catch (Throwable $throwable) {
            throw UnresolvedWorkflowFailureException::misconfigured($normalized, $throwable);
        }

        if ($resolution === null) {
            throw UnresolvedWorkflowFailureException::unresolved($normalized);
        }

        try {
            return self::restoreThrowable($resolution['class'], $normalized);
        } catch (Throwable $throwable) {
            throw UnresolvedWorkflowFailureException::unrestorable($normalized, $throwable);
        }
    }

    /**
     * @return array{class: ?class-string<Throwable>, source: 'exception_type'|'class_alias'|'recorded_class'|'unresolved'|'misconfigured'|'unrestorable', error: ?string}
     */
    public static function replayResolution(
        mixed $payload,
        ?string $fallbackClass = null,
        ?string $fallbackMessage = null,
        ?int $fallbackCode = null,
        ?string $fallbackType = null,
    ): array {
        $normalized = self::normalizePayload($payload, $fallbackClass, $fallbackMessage, $fallbackCode, $fallbackType);
        $class = $normalized['class'];

        try {
            $resolution = is_string($class)
                ? TypeRegistry::resolveThrowableClassWithSource($class, $normalized['type'])
                : null;
        } catch (Throwable $throwable) {
            return [
                'class' => null,
                'source' => 'misconfigured',
                'error' => $throwable->getMessage(),
            ];
        }

        if ($resolution === null) {
            return [
                'class' => null,
                'source' => 'unresolved',
                'error' => null,
            ];
        }

        try {
            self::restoreThrowable($resolution['class'], $normalized);
        } catch (Throwable $throwable) {
            return [
                'class' => $resolution['class'],
                'source' => 'unrestorable',
                'error' => $throwable->getMessage(),
            ];
        }

        return [
            'class' => $resolution['class'],
            'source' => $resolution['source'],
            'error' => null,
        ];
    }

    /**
     * Refine the failure category for terminal/update propagation by inspecting
     * the throwable type. Falls back to Application for user-space exceptions.
     */
    private static function classifyFromThrowable(?Throwable $throwable): FailureCategory
    {
        if ($throwable === null) {
            return FailureCategory::Application;
        }

        // Structural-limit failures: pending fan-out, payload size, metadata size.
        if ($throwable instanceof StructuralLimitExceededException) {
            return FailureCategory::StructuralLimit;
        }

        // Task-level failures: determinism violations, unsupported yields, replay shape errors.
        if (
            $throwable instanceof UnsupportedWorkflowYieldException
            || $throwable instanceof StraightLineWorkflowRequiredException
        ) {
            return FailureCategory::TaskFailure;
        }

        // Infrastructure failures: database, PDO, queue exhaustion.
        if (
            $throwable instanceof QueryException
            || $throwable instanceof PDOException
            || $throwable instanceof MaxAttemptsExceededException
        ) {
            return FailureCategory::Internal;
        }

        // Timeout failures: check message convention for timeout-induced failures.
        if (self::isTimeoutThrowable($throwable)) {
            return FailureCategory::Timeout;
        }

        // Structural-limit failures: check message convention as fallback.
        if (self::isStructuralLimitMessage($throwable->getMessage())) {
            return FailureCategory::StructuralLimit;
        }

        return FailureCategory::Application;
    }

    /**
     * Refine the failure category using exception class name and message strings.
     * Mirrors classifyFromThrowable logic using string matching instead of instanceof.
     */
    private static function classifyFromExceptionStrings(?string $exceptionClass, ?string $message): FailureCategory
    {
        if ($exceptionClass !== null) {
            // Structural-limit failures.
            if (self::classNameMatches($exceptionClass, StructuralLimitExceededException::class)) {
                return FailureCategory::StructuralLimit;
            }

            // Task-level failures: determinism violations, unsupported yields.
            if (
                self::classNameMatches($exceptionClass, UnsupportedWorkflowYieldException::class)
                || self::classNameMatches($exceptionClass, StraightLineWorkflowRequiredException::class)
            ) {
                return FailureCategory::TaskFailure;
            }

            // Infrastructure failures: database, PDO, queue exhaustion.
            if (
                self::classNameMatches($exceptionClass, QueryException::class)
                || self::classNameMatches($exceptionClass, PDOException::class)
                || self::classNameMatches($exceptionClass, MaxAttemptsExceededException::class)
            ) {
                return FailureCategory::Internal;
            }
        }

        // Timeout failures: check message convention.
        if ($message !== null && self::isTimeoutMessage($message)) {
            return FailureCategory::Timeout;
        }

        // Structural-limit failures: check message convention.
        if ($message !== null && self::isStructuralLimitMessage($message)) {
            return FailureCategory::StructuralLimit;
        }

        return FailureCategory::Application;
    }

    /**
     * Check if an exception class name matches a known class, accounting for
     * the class itself or any subclass whose fully qualified name ends with
     * the same short class name.
     */
    private static function classNameMatches(string $exceptionClass, string $knownClass): bool
    {
        if ($exceptionClass === $knownClass) {
            return true;
        }

        // Also match when the recorded class is a subclass of the known class
        // and is resolvable in the current runtime.
        if (class_exists($exceptionClass) && is_a($exceptionClass, $knownClass, true)) {
            return true;
        }

        return false;
    }

    /**
     * Detect throwables that represent timeout conditions. This checks for a
     * conventional marker interface or a message pattern indicating a timeout.
     */
    private static function isTimeoutThrowable(Throwable $throwable): bool
    {
        return self::isTimeoutMessage($throwable->getMessage());
    }

    /**
     * Check a message string for timeout-indicating patterns.
     */
    private static function isTimeoutMessage(string $message): bool
    {
        $message = strtolower($message);

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout exceeded')
            || str_contains($message, 'execution deadline')
            || str_contains($message, 'run deadline');
    }

    /**
     * Check a message string for structural-limit-indicating patterns.
     */
    private static function isStructuralLimitMessage(string $message): bool
    {
        return str_starts_with($message, 'Structural limit exceeded:');
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
        ?string $fallbackType = null,
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
                : $fallbackType,
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

            self::setPayloadProperty($throwable, $class, $property['declaring_class'], $property['name'], $value);
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
        $candidates = array_values(array_unique([$declaringClass, $restoredClass]));

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
