<?php

declare(strict_types=1);

namespace Workflow\States;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonSerializable;
use ReflectionClass;
use Workflow\Events\StateChanged;
use Workflow\Exceptions\TransitionNotFound;

abstract class State implements Castable, JsonSerializable
{
    private $model;

    private StateConfig $stateConfig;

    private string $field = '';

    /**
     * @var array<string, array<string, string>>
     */
    private static array $stateMapping = [];

    public function __construct($model)
    {
        $this->model = $model;
        $this->stateConfig = static::config();
    }

    public function __toString(): string
    {
        return $this->getValue();
    }

    public static function config(): StateConfig
    {
        $reflection = new ReflectionClass(static::class);
        $baseClass = $reflection->name;

        while (! $reflection->isAbstract() && ($parent = $reflection->getParentClass()) instanceof ReflectionClass) {
            $reflection = $parent;
            $baseClass = $reflection->name;
        }

        return new StateConfig($baseClass);
    }

    public static function castUsing(array $arguments)
    {
        return new StateCaster(static::class);
    }

    public static function getMorphClass(): string
    {
        $defaultProperties = (new ReflectionClass(static::class))->getDefaultProperties();
        $name = $defaultProperties['name'] ?? null;

        return is_string($name) ? $name : static::class;
    }

    public static function getStateMapping(): Collection
    {
        if (! isset(self::$stateMapping[static::class])) {
            self::$stateMapping[static::class] = static::resolveStateMapping();
        }

        return collect(self::$stateMapping[static::class]);
    }

    public static function resolveStateClass($state): ?string
    {
        if ($state === null) {
            return null;
        }

        if ($state instanceof self) {
            return $state::class;
        }

        if (is_string($state) && is_subclass_of($state, static::class)) {
            return $state;
        }

        foreach (static::getStateMapping() as $morphClass => $stateClass) {
            // Loose comparison is needed to support values casted to strings by Eloquent.
            if ($morphClass === $state) {
                return $stateClass;
            }
        }

        return is_string($state) ? $state : null;
    }

    public static function make(string $name, $model): self
    {
        $stateClass = static::resolveStateClass($name);

        if (! is_string($stateClass) || ! is_subclass_of($stateClass, static::class)) {
            throw new InvalidArgumentException("{$name} does not extend " . static::class . '.');
        }

        return new $stateClass($model);
    }

    public function getModel()
    {
        return $this->model;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public static function all(): Collection
    {
        return collect(self::resolveStateMapping());
    }

    public function setField(string $field): self
    {
        $this->field = $field;

        return $this;
    }

    public function transitionTo($newState, ...$transitionArgs)
    {
        $newState = $this->resolveStateObject($newState);

        $from = static::getMorphClass();
        $to = $newState::getMorphClass();

        if (! $this->stateConfig->isTransitionAllowed($from, $to)) {
            throw TransitionNotFound::make($from, $to, $this->model::class);
        }

        $this->model->{$this->field} = $newState;
        $this->model->save();
        $model = $this->model;
        $currentState = $model->{$this->field} ?? null;

        if ($currentState instanceof self) {
            $currentState->setField($this->field);
        }

        event(new StateChanged(
            $this,
            $currentState instanceof self ? $currentState : null,
            $this->model,
            $this->field
        ));

        return $model;
    }

    public function canTransitionTo($newState, ...$transitionArgs): bool
    {
        $newState = $this->resolveStateObject($newState);

        return $this->stateConfig->isTransitionAllowed(static::getMorphClass(), $newState::getMorphClass());
    }

    public function getValue(): string
    {
        return static::getMorphClass();
    }

    public function equals(...$otherStates): bool
    {
        foreach ($otherStates as $otherState) {
            $otherState = $this->resolveStateObject($otherState);

            if (
                $this->stateConfig->baseStateClass === $otherState->stateConfig->baseStateClass
                && $this->getValue() === $otherState->getValue()
            ) {
                return true;
            }
        }

        return false;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->getValue();
    }

    private function resolveStateObject($state): self
    {
        if (is_object($state) && is_subclass_of($state, $this->stateConfig->baseStateClass)) {
            return $state;
        }

        $stateClassName = $this->stateConfig->baseStateClass::resolveStateClass($state);

        if (! is_string($stateClassName) || ! is_subclass_of($stateClassName, $this->stateConfig->baseStateClass)) {
            throw new InvalidArgumentException("{$state} does not extend {$this->stateConfig->baseStateClass}.");
        }

        return new $stateClassName($this->model);
    }

    /**
     * @return array<string, string>
     */
    private static function resolveStateMapping(): array
    {
        $reflection = new ReflectionClass(static::class);
        $stateConfig = static::config();

        $fileName = (string) $reflection->getFileName();
        $files = @scandir(dirname($fileName));

        if ($files === false) {
            return [];
        }

        $namespace = $reflection->getNamespaceName();
        $resolvedStates = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $fileInfo = pathinfo($file);
            $extension = $fileInfo['extension'] ?? null;
            $className = $fileInfo['filename'] ?? null;

            if ($extension !== 'php' || ! is_string($className)) {
                continue;
            }

            $stateClass = $namespace . '\\' . $className;

            if (! class_exists($stateClass)) {
                continue;
            }

            if (! is_subclass_of($stateClass, $stateConfig->baseStateClass)) {
                continue;
            }

            $resolvedStates[$stateClass::getMorphClass()] = $stateClass;
        }

        foreach ($stateConfig->registeredStates as $stateClass) {
            $resolvedStates[$stateClass::getMorphClass()] = $stateClass;
        }

        return $resolvedStates;
    }
}
