<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use Workflow\V2\Exceptions\WorkflowEntryArgumentsMismatchException;
use Workflow\V2\Workflow;

/** @internal Validates the invocation before entering application workflow code. */
final class WorkflowEntryArguments
{
    public static function resolve(Workflow $workflow, array $recorded, ReflectionMethod $entry): array
    {
        try {
            $resolved = $workflow->resolveMethodDependencies($recorded, $entry);
        } catch (Throwable $exception) {
            throw self::mismatch($workflow, $entry, 'dependency_resolution_failed', previous: $exception);
        }

        // Laravel inserts injected objects before the recorded positional
        // values. Never let a newly inserted DTO silently push input beyond
        // the method's last parameter, where PHP would discard that input.
        if (! $entry->isVariadic() && count($resolved) > $entry->getNumberOfParameters()
            && count($resolved) > count($recorded)) {
            throw self::mismatch($workflow, $entry, 'recorded_argument_would_be_discarded');
        }

        return $resolved;
    }

    public static function assertInvocation(Workflow $workflow, ReflectionMethod $entry, array $arguments): void
    {
        $positional = [];
        $named = [];
        foreach ($arguments as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;
            } elseif ($named !== []) {
                throw self::mismatch($workflow, $entry, 'positional_argument_after_named_argument');
            } else {
                $positional[] = $value;
            }
        }

        foreach ($entry->getParameters() as $index => $parameter) {
            $name = $parameter->getName();
            if ($parameter->isVariadic()) {
                foreach ([...array_slice($positional, $index), ...$named] as $value) {
                    self::assertType($workflow, $entry, $parameter, $value);
                }

                return;
            }
            $hasPosition = array_key_exists($index, $positional);
            $hasName = array_key_exists($name, $named);
            if ($hasPosition && $hasName) {
                throw self::mismatch($workflow, $entry, 'argument_supplied_twice', $parameter);
            }
            if (! $hasPosition && ! $hasName) {
                if (! $parameter->isOptional()) {
                    throw self::mismatch($workflow, $entry, 'required_argument_missing', $parameter);
                }

                continue;
            }
            $value = $hasPosition ? $positional[$index] : $named[$name];
            unset($named[$name]);
            self::assertType($workflow, $entry, $parameter, $value);
        }
        if ($named !== []) {
            throw self::mismatch($workflow, $entry, 'unknown_named_argument');
        }
    }

    private static function assertType(
        Workflow $workflow,
        ReflectionMethod $entry,
        ReflectionParameter $parameter,
        mixed $value
    ): void {
        $type = $parameter->getType();
        if ($type !== null && ! self::matches($type, $parameter, $value)) {
            throw self::mismatch($workflow, $entry, 'argument_type_mismatch', $parameter, get_debug_type($value));
        }
    }

    private static function matches(ReflectionType $type, ReflectionParameter $parameter, mixed $value): bool
    {
        if ($value === null && $type->allowsNull()) {
            return true;
        }
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (self::matches($member, $parameter, $value)) {
                    return true;
                }
            }

            return false;
        }
        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (! self::matches($member, $parameter, $value)) {
                    return false;
                }
            }

            return true;
        }
        if (! $type instanceof ReflectionNamedType) {
            return false;
        }
        if (! $type->isBuiltin()) {
            $parent = $parameter->getDeclaringClass()?->getParentClass();
            $class = match ($type->getName()) {
                'self', 'static' => $parameter->getDeclaringClass()?->getName(),
                'parent' => $parent === false ? null : $parent?->getName(),
                default => $type->getName(),
            };

            return is_string($class) && $value instanceof $class;
        }

        return match ($type->getName()) {
            'mixed' => true,
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'true' => $value === true,
            'false' => $value === false,
            'null' => $value === null,
            'array' => is_array($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            'iterable' => is_iterable($value),
            default => false,
        };
    }

    private static function mismatch(
        Workflow $workflow,
        ReflectionMethod $entry,
        string $reason,
        ?ReflectionParameter $parameter = null,
        ?string $actualType = null,
        ?Throwable $previous = null,
    ): WorkflowEntryArgumentsMismatchException {
        return new WorkflowEntryArgumentsMismatchException(
            isset($workflow->run) ? ($workflow->run->workflow_type ?? $workflow::class) : $workflow::class,
            $entry->getName(),
            $reason,
            $parameter?->getName(),
            $parameter?->getType() === null ? null : (string) $parameter->getType(),
            $actualType,
            $previous,
        );
    }
}
