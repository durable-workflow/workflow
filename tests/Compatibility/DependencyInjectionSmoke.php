<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Workflow\Traits\ResolvesMethodDependencies;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class CompatibilityInjectedDependency
{
    public function __construct(
        public readonly string $value
    ) {
    }
}

final class CompatibilityResolver
{
    use ResolvesMethodDependencies;

    public function __construct(
        private Container $container
    ) {
    }

    public function execute(CompatibilityInjectedDependency $dependency, string $suffix = 'resolved'): string
    {
        return $dependency->value . '-' . $suffix;
    }

    public function run(array $parameters): string
    {
        $parameters = $this->resolveMethodDependencies($parameters, new \ReflectionMethod($this, 'execute'));

        return $this->execute(...$parameters);
    }
}

$container = new Container();
$container->instance(CompatibilityInjectedDependency::class, new CompatibilityInjectedDependency('injected'));

if ((new CompatibilityResolver($container))->run([]) !== 'injected-resolved') {
    throw new RuntimeException('Method dependency injection did not resolve the application dependency.');
}

if (! class_exists(Workflow\Workflow::class) || ! class_exists(Workflow\Activity::class)) {
    throw new RuntimeException('Workflow and activity classes did not load.');
}

echo 'Dependency injection compatibility smoke passed.' . PHP_EOL;
