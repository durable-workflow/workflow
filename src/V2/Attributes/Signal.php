<?php

declare(strict_types=1);

namespace Workflow\V2\Attributes;

use Attribute;
use LogicException;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Signal
{
    public readonly string $name;

    /**
     * @var list<array{
     *     name: string,
     *     position: int,
     *     required: bool,
     *     variadic: bool,
     *     default_available: bool,
     *     default: mixed,
     *     type: ?string,
     *     allows_null: bool
     * }>
     */
    public readonly array $parameters;

    /**
     * @param list<string|array{
     *     name: string,
     *     variadic?: bool,
     *     default?: mixed,
     *     type?: ?string,
     *     allows_null?: bool
     * }> $parameters
     */
    public function __construct(string $name, array $parameters = [])
    {
        $name = trim($name);

        if ($name === '') {
            throw new LogicException('V2 signal names must be non-empty strings.');
        }

        $this->name = $name;
        $this->parameters = self::normalizeParameters($name, $parameters);
    }

    /**
     * @param list<string|array{
     *     name: string,
     *     variadic?: bool,
     *     default?: mixed,
     *     type?: ?string,
     *     allows_null?: bool
     * }> $parameters
     * @return list<array{
     *     name: string,
     *     position: int,
     *     required: bool,
     *     variadic: bool,
     *     default_available: bool,
     *     default: mixed,
     *     type: ?string,
     *     allows_null: bool
     * }>
     */
    private static function normalizeParameters(string $signalName, array $parameters): array
    {
        if (! array_is_list($parameters)) {
            throw new LogicException(sprintf(
                'V2 signal [%s] parameters must be declared as a list.',
                $signalName,
            ));
        }

        $normalized = [];
        $seen = [];

        foreach ($parameters as $position => $parameter) {
            $definition = is_string($parameter)
                ? [
                    'name' => $parameter,
                ]
                : $parameter;

            if (! is_array($definition)) {
                throw new LogicException(sprintf(
                    'V2 signal [%s] parameter definitions must be strings or arrays.',
                    $signalName,
                ));
            }

            $parameterName = trim((string) ($definition['name'] ?? ''));

            if ($parameterName === '') {
                throw new LogicException(sprintf(
                    'V2 signal [%s] parameter names must be non-empty strings.',
                    $signalName,
                ));
            }

            if (in_array($parameterName, $seen, true)) {
                throw new LogicException(sprintf(
                    'V2 signal [%s] declares duplicate parameter [%s].',
                    $signalName,
                    $parameterName,
                ));
            }

            $variadic = (bool) ($definition['variadic'] ?? false);
            $defaultAvailable = array_key_exists('default', $definition);
            $type = $definition['type'] ?? null;
            $allowsNull = $definition['allows_null'] ?? true;

            if ($variadic && $defaultAvailable) {
                throw new LogicException(sprintf(
                    'V2 signal [%s] variadic parameter [%s] cannot declare a default value.',
                    $signalName,
                    $parameterName,
                ));
            }

            if ($type !== null && (! is_string($type) || trim($type) === '')) {
                throw new LogicException(sprintf(
                    'V2 signal [%s] parameter [%s] type must be a non-empty string when provided.',
                    $signalName,
                    $parameterName,
                ));
            }

            if (! is_bool($allowsNull)) {
                throw new LogicException(sprintf(
                    'V2 signal [%s] parameter [%s] allows_null must be a boolean.',
                    $signalName,
                    $parameterName,
                ));
            }

            $normalized[] = [
                'name' => $parameterName,
                'position' => $position,
                'required' => ! $defaultAvailable && ! $variadic,
                'variadic' => $variadic,
                'default_available' => $defaultAvailable,
                'default' => $defaultAvailable ? $definition['default'] : null,
                'type' => $type === null ? null : trim($type),
                'allows_null' => $allowsNull,
            ];

            $seen[] = $parameterName;
        }

        return $normalized;
    }
}
