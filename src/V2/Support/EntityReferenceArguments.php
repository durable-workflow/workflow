<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use InvalidArgumentException;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Workflow\V2\Activity;
use Workflow\V2\EntityReferences\EntityReference;
use Workflow\V2\EntityReferences\EntityReferenceAdapter;
use Workflow\V2\EntityReferences\EntityReferenceContext;
use Workflow\V2\EntityReferences\EntityReferenceRefused;
use Workflow\V2\EntityReferences\EntityReferenceResolver;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;

/** @internal Adapts recorded input and resolves it only at real activity attempts. */
final class EntityReferenceArguments
{
    public const POSITIONS = 'entity_reference_positions';

    /**
     * @return array{array, list<int>}
     */
    public static function encode(array $arguments): array
    {
        $positions = [];
        foreach ($arguments as $position => $value) {
            if (! is_object($value)) {
                continue;
            }
            $reference = null;
            foreach (self::registrations() as $type => $definition) {
                $definition = self::definition($type, $definition);
                $adapter = app()
                    ->make($definition['adapter']);
                if (! $value instanceof $definition['entity_class'] || ! $adapter->supports($value)) {
                    continue;
                }
                if ($reference !== null) {
                    throw new EntityReferenceRefused(
                        EntityReferenceRefused::AMBIGUOUS_TYPE,
                        'More than one entity type adapts this activity argument.'
                    );
                }
                $reference = new EntityReference($type, $adapter->identify($value), $adapter->version());
            }
            if ($reference !== null) {
                if (! is_int($position) || ! array_is_list($arguments)) {
                    throw new EntityReferenceRefused(
                        EntityReferenceRefused::INVALID_REFERENCE,
                        'Entity references require positional activity arguments.'
                    );
                }
                $arguments[$position] = $reference->toArray();
                $positions[] = $position;
            }
        }
        return [$arguments, $positions];
    }

    public static function resolve(Activity $activity, array $arguments, ReflectionMethod $entry): array
    {
        return self::resolveForAttempt($activity->run, $activity->execution, $arguments, $entry);
    }

    public static function resolveForAttempt(
        WorkflowRun $run,
        ActivityExecution $execution,
        array $arguments,
        ReflectionMethod $entry
    ): array {
        $options = $execution->activity_options ?? [];
        if (! is_array($options) || ! array_key_exists(self::POSITIONS, $options)) {
            return $arguments;
        }
        $positions = $options[self::POSITIONS];
        if ($positions === []) {
            return $arguments;
        }
        if (! is_array($positions) || ! array_is_list($positions) || ! array_is_list($arguments)) {
            throw self::invalid();
        }
        $references = [];
        $definitions = [];
        foreach ($positions as $position) {
            if (! is_int($position) || $position < 0 || isset($references[$position])
                || ! isset($arguments[$position]) || ! is_array($arguments[$position])) {
                throw self::invalid();
            }
            try {
                $reference = EntityReference::fromArray($arguments[$position]);
            } catch (InvalidArgumentException) {
                throw self::invalid();
            }
            $definition = self::definition($reference->type, self::registrations()[$reference->type] ?? null);
            $adapter = app()
                ->make($definition['adapter']);
            if ($reference->version !== $adapter->version()) {
                throw new EntityReferenceRefused(
                    EntityReferenceRefused::UNSUPPORTED_VERSION,
                    'The recorded entity reference version is not supported.'
                );
            }
            $references[$position] = $reference;
            $definitions[$position] = $definition;
        }
        $parameters = self::parameters($arguments, $definitions, $entry);
        foreach ($references as $position => $reference) {
            $definition = $definitions[$position];
            $parameter = $parameters[$position];
            $parameterType = $parameter->getType();
            $declaredType = $parameterType instanceof ReflectionNamedType && ! $parameterType->isBuiltin()
                ? $parameterType->getName() : null;
            $context = new EntityReferenceContext(
                namespace: $run->namespace,
                workflowInstanceId: $run->workflow_instance_id,
                workflowRunId: $run->id,
                activityExecutionId: $execution->id,
                attemptNumber: (int) $execution->attempt_count,
                argumentPosition: $position,
                parameterType: $declaredType,
            );
            $value = app()
                ->make($definition['resolver'])->resolve($reference, $context);
            if (! $value instanceof $definition['entity_class'] || ! self::accepts(
                $parameterType,
                $value::class,
                $parameter
            )) {
                throw self::mismatch();
            }
            $arguments[$position] = $value;
        }
        return $arguments;
    }

    /**
     * @return array<int, ReflectionParameter>
     */
    private static function parameters(array $arguments, array $definitions, ReflectionMethod $entry): array
    {
        $result = [];
        $position = 0;
        $classes = [];
        foreach ($arguments as $index => $value) {
            $classes[] = $definitions[$index]['entity_class'] ?? (is_object($value) ? $value::class : null);
        }
        foreach ($entry->getParameters() as $parameter) {
            if (! array_key_exists($position, $arguments)) {
                break;
            }
            $type = $parameter->getType();
            // Match the existing method-injection rule: an unmatched class
            // parameter is injected without consuming recorded input. The
            // method resolver checks all arguments, including later ones.
            $injected = $parameter->getAttributes(
                'Illuminate\Contracts\Container\ContextualAttribute',
                \ReflectionAttribute::IS_INSTANCEOF
            ) !== [];
            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin()) {
                $provided = false;
                foreach ($classes as $class) {
                    $provided = $provided || ($class !== null && self::accepts($type, $class, $parameter));
                }
                $injected = $injected || ! $provided;
            }
            if ($injected) {
                continue;
            }
            do {
                if (isset($definitions[$position])) {
                    if (! self::accepts($type, $definitions[$position]['entity_class'], $parameter)) {
                        throw self::mismatch();
                    }
                    $result[$position] = $parameter;
                }
                ++$position;
            } while ($parameter->isVariadic() && array_key_exists($position, $arguments));
        }
        if (count($result) !== count($definitions)) {
            throw self::mismatch();
        }
        return $result;
    }

    private static function accepts(?ReflectionType $type, string $class, ReflectionParameter $parameter): bool
    {
        if ($type === null) {
            return true;
        }
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $part) {
                if (self::accepts($part, $class, $parameter)) {
                    return true;
                }
            }
            return false;
        }
        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $part) {
                if (! self::accepts($part, $class, $parameter)) {
                    return false;
                }
            }
            return true;
        }
        $name = $type instanceof ReflectionNamedType ? $type->getName() : '';
        $declaring = $parameter->getDeclaringClass();
        if ($name === 'self') {
            $name = $declaring?->getName() ?? $name;
        } elseif ($name === 'parent') {
            $parent = $declaring?->getParentClass();
            $name = $parent ? $parent->getName() : $name;
        }
        return $type instanceof ReflectionNamedType && ($type->isBuiltin()
            ? in_array($type->getName(), ['mixed', 'object'], true)
            : is_a($class, $name, true));
    }

    private static function registrations(): array
    {
        $definitions = config('workflows.v2.entity_references', []);
        if (! is_array($definitions)) {
            throw new EntityReferenceRefused(
                EntityReferenceRefused::INVALID_REGISTRATION,
                'Entity reference registrations must be a type map.'
            );
        }
        return $definitions;
    }

    private static function definition(mixed $type, mixed $definition): array
    {
        if ($definition === null) {
            throw new EntityReferenceRefused(
                EntityReferenceRefused::UNKNOWN_TYPE,
                'The recorded entity type is not registered.'
            );
        }
        if (! is_string($type) || $type === '' || ! is_array($definition)
            || ! is_string($definition['entity_class'] ?? null)
            || (! class_exists($definition['entity_class']) && ! interface_exists($definition['entity_class']))
            || ! is_string($definition['adapter'] ?? null) || ! is_a(
                $definition['adapter'],
                EntityReferenceAdapter::class,
                true
            )
            || ! is_string($definition['resolver'] ?? null) || ! is_a(
                $definition['resolver'],
                EntityReferenceResolver::class,
                true
            )) {
            throw new EntityReferenceRefused(
                EntityReferenceRefused::INVALID_REGISTRATION,
                'An entity type needs its entity_class, adapter and resolver registrations.'
            );
        }
        return $definition;
    }

    private static function invalid(): EntityReferenceRefused
    {
        return new EntityReferenceRefused(
            EntityReferenceRefused::INVALID_REFERENCE,
            'Recorded entity reference metadata is invalid.'
        );
    }

    private static function mismatch(): EntityReferenceRefused
    {
        return new EntityReferenceRefused(
            EntityReferenceRefused::PARAMETER_MISMATCH,
            'The registered entity type does not match its activity parameter.'
        );
    }
}
