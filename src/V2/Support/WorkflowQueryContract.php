<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Models\WorkflowRun;

final class WorkflowQueryContract
{
    /**
     * @return array{name: string, method: string}|null
     */
    public static function resolveTargetForRun(WorkflowRun $run, string $target): ?array
    {
        try {
            $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
        } catch (LogicException) {
            return RunCommandContract::hasQueryMethod($run, $target)
                ? [
                    'name' => $target,
                    'method' => $target,
                ]
                : null;
        }

        return WorkflowDefinition::resolveQueryTarget($workflowClass, $target);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    public static function validatedArgumentsForRun(WorkflowRun $run, string $queryName, array $arguments): array
    {
        if (array_is_list($arguments)) {
            $normalized = array_values($arguments);
        } else {
            $normalized = [];
        }

        $contract = RunCommandContract::queryContract($run, $queryName);

        if ($contract === null) {
            try {
                $workflowClass = TypeRegistry::resolveWorkflowClass($run->workflow_class, $run->workflow_type);
            } catch (LogicException) {
                return array_is_list($arguments)
                    ? [
                        'arguments' => $normalized,
                        'validation_errors' => [],
                    ]
                    : [
                        'arguments' => [],
                        'validation_errors' => [
                            'arguments' => ['Named arguments require a durable or loadable workflow query contract.'],
                        ],
                    ];
            }

            $contract = WorkflowDefinition::queryContract($workflowClass, $queryName);
        }

        if ($contract === null) {
            return array_is_list($arguments)
                ? [
                    'arguments' => $normalized,
                    'validation_errors' => [],
                ]
                : [
                    'arguments' => [],
                    'validation_errors' => [
                        'arguments' => ['Named arguments require a durable or loadable workflow query contract.'],
                    ],
                ];
        }

        return array_is_list($arguments)
            ? self::normalizePositionalArguments($contract, $arguments)
            : self::normalizeNamedArguments($contract, $arguments);
    }

    /**
     * @param array{name: string, parameters: list<array<string, mixed>>} $contract
     * @param array<int, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private static function normalizePositionalArguments(array $contract, array $arguments): array
    {
        $normalized = [];
        $errors = [];
        $providedCount = count($arguments);
        $consumed = 0;

        foreach ($contract['parameters'] as $parameter) {
            if (($parameter['variadic'] ?? false) === true) {
                while ($consumed < $providedCount) {
                    $normalized[] = $arguments[$consumed];
                    self::appendParameterValidationErrors($errors, $parameter, $arguments[$consumed]);
                    $consumed++;
                }

                continue;
            }

            if ($consumed < $providedCount) {
                $normalized[] = $arguments[$consumed];
                self::appendParameterValidationErrors($errors, $parameter, $arguments[$consumed]);
                $consumed++;

                continue;
            }

            if (($parameter['default_available'] ?? false) === true) {
                $normalized[] = $parameter['default'] ?? null;

                continue;
            }

            if (($parameter['required'] ?? false) === true) {
                $errors[$parameter['name']][] = sprintf('The %s argument is required.', $parameter['name']);
            }
        }

        if ($consumed < $providedCount) {
            $errors['arguments'][] = sprintf(
                'Too many arguments were provided for query [%s].',
                $contract['name'],
            );
        }

        return [
            'arguments' => $normalized,
            'validation_errors' => $errors,
        ];
    }

    /**
     * @param array{name: string, parameters: list<array<string, mixed>>} $contract
     * @param array<string, mixed> $arguments
     * @return array{arguments: list<mixed>, validation_errors: array<string, list<string>>}
     */
    private static function normalizeNamedArguments(array $contract, array $arguments): array
    {
        $normalized = [];
        $errors = [];
        $knownParameters = [];

        foreach ($contract['parameters'] as $parameter) {
            $name = $parameter['name'];
            $knownParameters[] = $name;

            if (($parameter['variadic'] ?? false) === true) {
                if (! array_key_exists($name, $arguments)) {
                    continue;
                }

                $values = $arguments[$name];

                if (is_array($values)) {
                    foreach (array_values($values) as $value) {
                        $normalized[] = $value;
                        self::appendParameterValidationErrors($errors, $parameter, $value);
                    }
                } else {
                    $normalized[] = $values;
                    self::appendParameterValidationErrors($errors, $parameter, $values);
                }

                continue;
            }

            if (array_key_exists($name, $arguments)) {
                $normalized[] = $arguments[$name];
                self::appendParameterValidationErrors($errors, $parameter, $arguments[$name]);

                continue;
            }

            if (($parameter['default_available'] ?? false) === true) {
                $normalized[] = $parameter['default'] ?? null;

                continue;
            }

            if (($parameter['required'] ?? false) === true) {
                $errors[$name][] = sprintf('The %s argument is required.', $name);
            }
        }

        foreach (array_keys($arguments) as $name) {
            if (in_array($name, $knownParameters, true)) {
                continue;
            }

            $errors[(string) $name][] = sprintf('Unknown argument [%s].', (string) $name);
        }

        return [
            'arguments' => $normalized,
            'validation_errors' => $errors,
        ];
    }

    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed> $parameter
     */
    private static function appendParameterValidationErrors(array &$errors, array $parameter, mixed $value): void
    {
        $name = is_string($parameter['name'] ?? null)
            ? $parameter['name']
            : 'argument';

        foreach (self::validationErrorsForParameterValue($parameter, $value) as $message) {
            $errors[$name][] = $message;
        }
    }

    /**
     * @param array<string, mixed> $parameter
     * @return list<string>
     */
    private static function validationErrorsForParameterValue(array $parameter, mixed $value): array
    {
        $name = is_string($parameter['name'] ?? null)
            ? $parameter['name']
            : 'argument';

        if ($value === null) {
            return self::parameterAllowsNull($parameter)
                ? []
                : [sprintf('The %s argument cannot be null.', $name)];
        }

        $type = is_string($parameter['type'] ?? null)
            ? trim($parameter['type'])
            : null;

        if ($type === null || $type === '' || $type === 'mixed') {
            return [];
        }

        if (self::valueMatchesDeclaredType($value, $type)) {
            return [];
        }

        return [sprintf('The %s argument must be of type %s.', $name, $type)];
    }

    /**
     * @param array<string, mixed> $parameter
     */
    private static function parameterAllowsNull(array $parameter): bool
    {
        if (is_bool($parameter['allows_null'] ?? null)) {
            return $parameter['allows_null'];
        }

        $type = is_string($parameter['type'] ?? null)
            ? trim($parameter['type'])
            : null;

        if ($type === null || $type === '') {
            return true;
        }

        return str_starts_with($type, '?')
            || in_array('null', self::splitDeclaredType($type, '|'), true);
    }

    private static function valueMatchesDeclaredType(mixed $value, string $type): bool
    {
        $type = trim($type);

        if ($type === '' || $type === 'mixed') {
            return true;
        }

        if (str_starts_with($type, '?')) {
            return $value === null || self::valueMatchesDeclaredType($value, substr($type, 1));
        }

        $unionTypes = self::splitDeclaredType($type, '|');

        if (count($unionTypes) > 1) {
            foreach ($unionTypes as $unionType) {
                if (self::valueMatchesDeclaredType($value, $unionType)) {
                    return true;
                }
            }

            return false;
        }

        $intersectionTypes = self::splitDeclaredType($type, '&');

        if (count($intersectionTypes) > 1) {
            foreach ($intersectionTypes as $intersectionType) {
                if (! self::valueMatchesDeclaredType($value, $intersectionType)) {
                    return false;
                }
            }

            return true;
        }

        $type = trim($type, "() \t\n\r\0\x0B");

        return match ($type) {
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            'object' => is_object($value),
            'callable' => is_callable($value),
            'iterable' => is_iterable($value),
            'scalar' => is_scalar($value),
            'true' => $value === true,
            'false' => $value === false,
            'null' => $value === null,
            'mixed' => true,
            'never', 'void' => false,
            'self', 'static', 'parent' => is_object($value),
            default => is_object($value)
                && (
                    ! class_exists($type)
                    && ! interface_exists($type)
                    && ! enum_exists($type)
                    || $value instanceof $type
                ),
        };
    }

    /**
     * @return list<string>
     */
    private static function splitDeclaredType(string $type, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $depth = 0;

        for ($index = 0, $length = strlen($type); $index < $length; $index++) {
            $character = $type[$index];

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')' && $depth > 0) {
                $depth--;
            }

            if ($character === $delimiter && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $current .= $character;
        }

        $parts[] = trim($current);

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
