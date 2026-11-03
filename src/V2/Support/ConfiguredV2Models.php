<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

/**
 * Resolves configurable Eloquent model classes referenced by v2 runtime and
 * server components so a host application can swap a model implementation
 * without forking the package.
 *
 * @api Stable class surface consumed by the standalone workflow-server.
 *      The public static method signatures on this class are covered by
 *      the workflow package's semver guarantee. See docs/api-stability.md.
 */
final class ConfiguredV2Models
{
    /**
     * @var array<string, array{default: class-string<Model>, relations: list<string>}>
     */
    private const RELATION_OVERRIDE_MATRIX = [
        'instance_model' => [
            'default' => WorkflowInstance::class,
            'relations' => ['runs', 'commands', 'updates'],
        ],
    ];

    /**
     * @param class-string<Model> $default
     * @return class-string<Model>
     */
    public static function resolve(string $configKey, string $default): string
    {
        $configured = config('workflows.v2.' . $configKey, $default);

        return is_string($configured) && is_a($configured, $default, true)
            ? $configured
            : $default;
    }

    /**
     * @param class-string<Model> $default
     * @return Builder<Model>
     */
    public static function query(string $configKey, string $default): Builder
    {
        /** @var class-string<Model> $model */
        $model = self::resolve($configKey, $default);

        return $model::query();
    }

    /**
     * Resolve the same storage connection used by durable workflow models.
     */
    public static function connection(): Connection
    {
        $name = Config::get('workflows.storage.connection');
        if (is_string($name) && $name !== '') {
            return DB::connection($name);
        }

        $model = Config::get('workflows.v2.run_model');

        return is_string($model) && $model !== WorkflowRun::class && is_a($model, WorkflowRun::class, true)
            ? (new $model())->getConnection()
            : DB::connection();
    }

    public static function validateConfiguration(): void
    {
        foreach (self::RELATION_OVERRIDE_MATRIX as $configKey => $validation) {
            $default = $validation['default'];
            $configured = self::resolve($configKey, $default);

            if ($configured === $default) {
                continue;
            }

            /** @var Model $configuredModel */
            $configuredModel = new $configured();

            if ($configuredModel->getForeignKey() === (new $default())->getForeignKey()) {
                continue;
            }

            $mismatches = [];

            foreach ($validation['relations'] as $relationName) {
                $declaringClass = (new \ReflectionMethod($configured, $relationName))->getDeclaringClass()
                    ->getName();

                if ($declaringClass === $default) {
                    $mismatches[] = sprintf(
                        '%s() must be overridden to keep workflow_instance_id-backed relations aligned.',
                        $relationName,
                    );
                }
            }

            if ($mismatches === []) {
                continue;
            }

            throw new \RuntimeException(sprintf(
                'Configured workflows.v2.%s [%s] changes v2 relation keys. Schema-compatible subclasses are safe, but table-swapped or basename-changing instance models must override the affected relations to keep workflow_instance_id-backed writes aligned. %s',
                $configKey,
                $configured,
                implode(' ', $mismatches),
            ));
        }
    }
}
