<?php

declare(strict_types=1);

namespace Tests\Unit\Traits;

use Attribute;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionMethod;
use ReflectionParameter;
use Workflow\Traits\ResolvesMethodDependencies;

final class ResolvesMethodDependenciesTest extends TestCase
{
    public function testAfterResolvingAttributeCallbacksReceiveTheResolvedDependency(): void
    {
        $container = new AttributeAwareResolverContainer();

        $parameters = $this->resolver($container)
            ->resolve([], new ReflectionMethod(ResolverTarget::class, 'observed'));

        $this->assertCount(1, $parameters);
        $this->assertInstanceOf(ResolverDependency::class, $parameters[0]);
        $this->assertSame(['service', $parameters[0]], $container->callbackArguments);
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
        $this->defineContextualAttributeFixtures();

        $parameters = $this->resolver(new AttributeAwareResolverContainer())
            ->resolve([], new ReflectionMethod(__NAMESPACE__ . '\\ContextualResolverTarget', 'contextual'));

        $this->assertSame(['from-attribute'], $parameters);
    }

    public function testEnumClassAndScalarDefaultsArePreservedWithoutContainerResolution(): void
    {
        $container = new BasicResolverContainer();

        $parameters = $this->resolver($container)
            ->resolve([], new ReflectionMethod(ResolverTarget::class, 'defaults'));

        $this->assertSame([ResolverMode::Standard, null, 'fallback'], $parameters);
        $this->assertSame(0, $container->makeCalls);
    }

    public function testSuppliedClassParametersAreNotResolvedOrDuplicated(): void
    {
        $dependency = new ResolverDependency();
        $container = new BasicResolverContainer();

        $parameters = $this->resolver($container)
            ->resolve([$dependency], new ReflectionMethod(ResolverTarget::class, 'dependency'));

        $this->assertSame([$dependency], $parameters);
        $this->assertSame(0, $container->makeCalls);
    }

    public function testBuiltInAndUntypedParametersRemainUnchanged(): void
    {
        $container = new BasicResolverContainer();
        $parameters = ['Taylor', 42];

        $this->assertSame(
            $parameters,
            $this->resolver($container)
                ->resolve($parameters, new ReflectionMethod(ResolverTarget::class, 'primitives')),
        );
        $this->assertSame(0, $container->makeCalls);
    }

    public function testNonContextualAttributesFallThroughToOrdinaryClassResolution(): void
    {
        $parameters = $this->resolver(new AttributeAwareResolverContainer())
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

    private function resolver(?object $container = null): ResolverHarness
    {
        return new ResolverHarness($container ?? new BasicResolverContainer());
    }

    private function defineContextualAttributeFixtures(): void
    {
        if (! interface_exists('Illuminate\\Contracts\\Container\\ContextualAttribute')) {
            eval('namespace Illuminate\\Contracts\\Container; interface ContextualAttribute {}');
        }

        if (! class_exists(__NAMESPACE__ . '\\ContextValue', false)) {
            eval(<<<'PHP'
                namespace Tests\Unit\Traits;

                #[\Attribute(\Attribute::TARGET_PARAMETER)]
                final class ContextValue implements \Illuminate\Contracts\Container\ContextualAttribute
                {
                    public function __construct(public string $value)
                    {
                    }
                }

                final class ContextualResolverTarget
                {
                    public function contextual(#[ContextValue('from-attribute')] string $value): void
                    {
                    }
                }
                PHP);
        }
    }
}

final class ResolverHarness
{
    use ResolvesMethodDependencies;

    public function __construct(
        public object $container
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

class BasicResolverContainer
{
    public int $makeCalls = 0;

    public function make(string $abstract): object
    {
        $this->makeCalls++;

        return new $abstract();
    }
}

final class AttributeAwareResolverContainer extends BasicResolverContainer
{
    /**
     * @var array{string, object}|array{}
     */
    public array $callbackArguments = [];

    public function resolveFromAttribute(ReflectionAttribute $attribute, ReflectionParameter $parameter): mixed
    {
        $context = $attribute->newInstance();

        return $context->value;
    }

    /**
     * @param array<int, ReflectionAttribute> $attributes
     */
    public function fireAfterResolvingAttributeCallbacks(array $attributes, mixed $dependency): void
    {
        foreach ($attributes as $attribute) {
            if ($attribute->getName() !== ObservedDependency::class) {
                continue;
            }

            $this->callbackArguments = [$attribute->newInstance()->label, $dependency];
        }
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

    public function defaults(
        ResolverMode $mode = ResolverMode::Standard,
        ?ResolverDependency $dependency = null,
        string $name = 'fallback',
    ): void {
    }

    public function dependency(ResolverDependency $dependency): void
    {
    }

    public function primitives(string $name, $value): void
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
