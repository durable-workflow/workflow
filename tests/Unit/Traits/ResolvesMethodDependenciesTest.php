<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use Attribute;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Workflow\Traits\ResolvesMethodDependencies;

final class ResolvesMethodDependenciesTest extends TestCase
{
    public function testAfterResolvingAttributeCallbacksReceiveTheResolvedDependency(): void
    {
        $container = new Container();
        $callbackArguments = [];
        $container->afterResolvingAttribute(
            ObservedDependency::class,
            static function (ObservedDependency $attribute, object $dependency) use (&$callbackArguments): void {
                $callbackArguments = [$attribute->label, $dependency];
            },
        );

        $parameters = $this->resolver($container)
            ->resolve([], new ReflectionMethod(ResolverTarget::class, 'observed'));

        $this->assertCount(1, $parameters);
        $this->assertInstanceOf(ResolverDependency::class, $parameters[0]);
        $this->assertSame(['service', $parameters[0]], $callbackArguments);
    }

    public function testMissingReflectedMethodsLeaveParametersUnchanged(): void
    {
        $parameters = [
            'bound' => 'value',
        ];

        $this->assertSame(
            $parameters,
            $this->resolver()
                ->resolveClass($parameters, new ResolverTarget(), 'missing'),
        );
    }

    public function testExistingReflectedMethodsResolveTheirDependencies(): void
    {
        $parameters = $this->resolver()
            ->resolveClass([], new ResolverTarget(), 'dependency');

        $this->assertCount(1, $parameters);
        $this->assertInstanceOf(ResolverDependency::class, $parameters[0]);
    }

    public function testContextualAttributesResolveThroughTheContainer(): void
    {
        if (! interface_exists(ContextualAttribute::class)) {
            $this->markTestSkipped('This Laravel version does not expose contextual attributes.');
        }

        $container = new Container();
        $container->whenHasAttribute(
            ContextValue::class,
            static fn (ContextValue $attribute): string => $attribute->value,
        );

        $parameters = $this->resolver($container)
            ->resolve([], new ReflectionMethod(ResolverTarget::class, 'contextual'));

        $this->assertSame(['from-attribute'], $parameters);
    }

    public function testEnumAndClassDefaultsArePreservedWithoutContainerResolution(): void
    {
        $container = new class() extends Container {
            public int $makeCalls = 0;

            public function make($abstract, array $parameters = [])
            {
                $this->makeCalls++;

                return parent::make($abstract, $parameters);
            }
        };

        $parameters = $this->resolver($container)
            ->resolve([], new ReflectionMethod(ResolverTarget::class, 'defaults'));

        $this->assertSame([ResolverMode::Standard, null, 'fallback'], $parameters);
        $this->assertSame(0, $container->makeCalls);
    }

    public function testSuppliedClassParametersAreNotResolvedOrDuplicated(): void
    {
        $dependency = new ResolverDependency();
        $container = new class() extends Container {
            public int $makeCalls = 0;

            public function make($abstract, array $parameters = [])
            {
                $this->makeCalls++;

                return parent::make($abstract, $parameters);
            }
        };

        $parameters = $this->resolver($container)
            ->resolve([$dependency], new ReflectionMethod(ResolverTarget::class, 'dependency'));

        $this->assertSame([$dependency], $parameters);
        $this->assertSame(0, $container->makeCalls);
    }

    public function testNonContextualAttributesFallThroughToOrdinaryClassResolution(): void
    {
        $parameters = $this->resolver()
            ->resolve([], new ReflectionMethod(ResolverTarget::class, 'observed'));

        $this->assertCount(1, $parameters);
        $this->assertInstanceOf(ResolverDependency::class, $parameters[0]);
    }

    public function testSelfAndParentParameterTypesResolveToTheirDeclaringClasses(): void
    {
        $resolver = $this->resolver();

        $self = $resolver->resolve([], new ReflectionMethod(ResolverChildTarget::class, 'selfDependency'));
        $parent = $resolver->resolve([], new ReflectionMethod(ResolverChildTarget::class, 'parentDependency'));

        $this->assertCount(1, $self);
        $this->assertInstanceOf(ResolverChildTarget::class, $self[0]);
        $this->assertCount(1, $parent);
        $this->assertInstanceOf(ResolverParentTarget::class, $parent[0]);
    }

    private function resolver(?Container $container = null): ResolverHarness
    {
        return new ResolverHarness($container ?? new Container());
    }
}

final class ResolverHarness
{
    use ResolvesMethodDependencies;

    public function __construct(
        public Container $container
    ) {
    }

    /**
     * @return array<int|string, mixed>
     */
    public function resolve(array $parameters, ReflectionMethod $method): array
    {
        return $this->resolveMethodDependencies($parameters, $method);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function resolveClass(array $parameters, object $instance, string $method): array
    {
        return $this->resolveClassMethodDependencies($parameters, $instance, $method);
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ObservedDependency
{
    public function __construct(
        public string $label
    ) {
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ContextValue implements ContextualAttribute
{
    public function __construct(
        public string $value
    ) {
    }
}

final class ResolverDependency
{
}

enum ResolverMode
{
    case Standard;
}

final class ResolverTarget
{
    public function observed(#[ObservedDependency('service')] ResolverDependency $dependency): void
    {
    }

    public function contextual(#[ContextValue('from-attribute')] string $value): void
    {
    }

    public function defaults(
        ResolverMode $mode = ResolverMode::Standard,
        ?ResolverDependency $dependency = null,
        string $name = 'fallback',
    ): void {
    }

    public function dependency(ResolverDependency $dependency): void
    {
    }
}

class ResolverParentTarget
{
}

final class ResolverChildTarget extends ResolverParentTarget
{
    public function selfDependency(self $dependency): void
    {
    }

    public function parentDependency(parent $dependency): void
    {
    }
}
