<?php

declare(strict_types=1);

namespace Workflow\States;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait HasStates
{
    public static function bootHasStates(): void
    {
        self::creating(static function ($model): void {
            $model->setStateDefaults();
        });
    }

    public function initializeHasStates(): void
    {
        $this->setStateDefaults();
    }

    public static function getStates(): Collection
    {
        $model = static::make();

        return collect($model->getStateConfigs())
            ->map(static function (StateConfig $stateConfig) {
                return $stateConfig->baseStateClass::getStateMapping()->keys();
            });
    }

    public static function getDefaultStates(): Collection
    {
        $model = static::make();

        return collect($model->getStateConfigs())
            ->map(static function (StateConfig $stateConfig) {
                $defaultStateClass = $stateConfig->defaultStateClass;

                if ($defaultStateClass === null) {
                    return null;
                }

                return $defaultStateClass::getMorphClass();
            });
    }

    public static function getDefaultStateFor(string $fieldName): ?string
    {
        return static::getDefaultStates()[$fieldName] ?? null;
    }

    public static function getStatesFor(string $fieldName): Collection
    {
        return collect(static::getStates()[$fieldName] ?? []);
    }

    public function scopeWhereState(Builder $builder, string $column, $states): Builder
    {
        $states = Arr::wrap($states);
        $field = Str::afterLast($column, '.');

        return $builder->whereIn($column, $this->getStateNamesForQuery($field, $states));
    }

    public function scopeWhereNotState(Builder $builder, string $column, $states): Builder
    {
        $states = Arr::wrap($states);
        $field = Str::afterLast($column, '.');

        return $builder->whereNotIn($column, $this->getStateNamesForQuery($field, $states));
    }

    public function scopeOrWhereState(Builder $builder, string $column, $states): Builder
    {
        $states = Arr::wrap($states);
        $field = Str::afterLast($column, '.');

        return $builder->orWhereIn($column, $this->getStateNamesForQuery($field, $states));
    }

    public function scopeOrWhereNotState(Builder $builder, string $column, $states): Builder
    {
        $states = Arr::wrap($states);
        $field = Str::afterLast($column, '.');

        return $builder->orWhereNotIn($column, $this->getStateNamesForQuery($field, $states));
    }

    /**
     * @return array<string, StateConfig>
     */
    private function getStateConfigs(): array
    {
        $states = [];

        foreach ($this->getCasts() as $field => $state) {
            if (! is_subclass_of($state, State::class)) {
                continue;
            }

            $states[$field] = $state::config();
        }

        return $states;
    }

    private function getStateNamesForQuery(string $field, array $states): Collection
    {
        $stateConfig = $this->getStateConfigs()[$field] ?? null;

        if ($stateConfig === null) {
            return collect([]);
        }

        return $stateConfig->baseStateClass::getStateMapping()
            ->filter(static function (string $className, string $morphName) use ($states) {
                return in_array($className, $states, true)
                    || in_array($morphName, $states, true);
            })
            ->keys();
    }

    private function setStateDefaults(): void
    {
        foreach ($this->getStateConfigs() as $field => $stateConfig) {
            if ($this->{$field} !== null) {
                continue;
            }

            if ($stateConfig->defaultStateClass === null) {
                continue;
            }

            $this->{$field} = $stateConfig->defaultStateClass;
        }
    }
}
