<?php

declare(strict_types=1);

namespace Tests\Unit\V2\Compensation;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * Pins the public signatures of the stable compensation-scope surface (#1063).
 *
 * Members marked @internal or @experimental are excluded from the pinned surface. Changing a pinned
 * signature is an API change: update docs/api-stability.md#compensation-scopes in the same change.
 */
final class CompensationPublicSurfaceTest extends TestCase
{
    /**
     * @return array<class-string, list<string>>
     */
    public static function expectedSurface(): array
    {
        return require __DIR__ . '/compensation-public-surface.php';
    }

    public function testPublicSignaturesMatchThePinnedSurface(): void
    {
        $actual = [];
        foreach (array_keys(self::expectedSurface()) as $class) {
            $actual[$class] = self::surface($class);
        }
        $this->assertSame(self::expectedSurface(), $actual);
    }

    public function testExcludedMembersAreExplicitlyMarked(): void
    {
        $method = new ReflectionMethod(\Workflow\V2\Compensation\DurableScope::class, 'reserveChild');
        $this->assertStringContainsString('@experimental', (string) $method->getDocComment());
        foreach ([
            [\Workflow\V2\Compensation\Recovery\ScopeRecovery::class, '__construct'],
            [\Workflow\V2\Compensation\Recovery\ScopeRetention::class, '__construct'],
            [\Workflow\V2\Compensation\Definition\Obligation::class, 'fromStored'],
            [\Workflow\V2\Compensation\Execution\HandlerRegistry::class, 'resolve'],
            [\Workflow\V2\Compensation\Definition\ScopeIdentity::class, 'recoveryInstanceId'],
            [\Workflow\V2\Compensation\Execution\ForwardResult::class, 'fromArray'],
            [\Workflow\V2\Compensation\Execution\Resolution::class, 'fromArray'],
            [\Workflow\V2\Compensation\Execution\ForwardResult::class, 'assertValueWithinLimits'],
        ] as [$class, $name]) {
            $this->assertStringContainsString(
                '@internal',
                (string) (new ReflectionMethod($class, $name))->getDocComment(),
                "{$class}::{$name} must be @internal"
            );
        }
    }

    /**
     * @return list<string>
     */
    public static function surface(string $class): array
    {
        $reflection = new ReflectionClass($class);
        if ($reflection->isEnum()) {
            $enum = new ReflectionEnum($class);
            return [
                'kind enum: ' . self::type($enum->getBackingType()),
                ...array_map(
                    static fn ($case): string => 'case ' . $case->getName() . ' = ' . self::value(
                        $case->getBackingValue()
                    ),
                    $enum->getCases(),
                ),
            ];
        }
        $members = ['kind ' . self::kind($reflection)];
        foreach ($reflection->getReflectionConstants() as $constant) {
            if ($constant->isPublic() && $constant->getDeclaringClass()->getName() === $class) {
                $members[] = 'const ' . $constant->getName() . ' = ' . self::value($constant->getValue());
            }
        }
        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->getDeclaringClass()->getName() !== $class || self::excluded($property->getDocComment())) {
                continue;
            }
            $members[] = 'property ' . self::type($property->getType(), $class) . ' $' . $property->getName();
        }
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class || self::excluded($method->getDocComment())) {
                continue;
            }
            $parameters = array_map(self::parameter(...), $method->getParameters());
            $members[] = ($method->isStatic() ? 'static ' : '') . $method->getName()
                . '(' . implode(', ', $parameters) . ')'
                . ($method->hasReturnType() ? ': ' . self::type($method->getReturnType(), $class) : '');
        }
        return $members;
    }

    /**
     * Pins final, readonly, abstract and interface-ness, and the parent class.
     */
    private static function kind(ReflectionClass $reflection): string
    {
        if ($reflection->isInterface()) {
            return 'interface';
        }
        $parent = $reflection->getParentClass();
        return implode(' ', array_filter([
            $reflection->isAbstract() ? 'abstract' : null,
            $reflection->isFinal() ? 'final' : null,
            $reflection->isReadOnly() ? 'readonly' : null,
            'class',
            $parent === false ? null : 'extends ' . $parent->getName(),
        ]));
    }

    private static function excluded(string|false $doc): bool
    {
        return is_string($doc) && (str_contains($doc, '@internal') || str_contains($doc, '@experimental'));
    }

    private static function parameter(ReflectionParameter $parameter): string
    {
        $text = self::type(
            $parameter->getType(),
            $parameter->getDeclaringClass()?->getName() ?? ''
        ) . ' ' . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();
        if ($parameter->isDefaultValueAvailable()) {
            $text .= ' = ' . self::value($parameter->getDefaultValue());
        }
        return ltrim($text);
    }

    /**
     * A PHP-version-independent rendering of constant and default values.
     */
    private static function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => "'" . $value . "'",
            is_array($value) => json_encode($value, JSON_THROW_ON_ERROR),
            $value instanceof \UnitEnum => $value::class . '::' . $value->name,
            default => (string) $value,
        };
    }

    private static function type(?ReflectionType $type, string $class = ''): string
    {
        if ($type === null) {
            return '';
        }
        if ($type instanceof ReflectionNamedType) {
            // PHP 8.5 reports a declared self type by its class name; pin the declared form.
            $name = $class !== '' && $type->getName() === $class ? 'self' : $type->getName();
            return ($type->allowsNull() && $name !== 'mixed' && $name !== 'null' ? '?' : '') . $name;
        }
        return (string) $type;
    }
}
