<?php

declare(strict_types=1);

namespace Workflow\Queue;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\QueueServiceProvider;
use ReflectionFunction;

/** Access the protected connector registry through Laravel's QueueManager extension seam. */
final class DatabaseQueueCompatibility extends QueueManager
{
    public static function install(QueueManager $manager): void
    {
        // Custom managers and connectors remain owned by their application.
        if ($manager::class !== QueueManager::class
            || ! class_exists(Application::class)
            || version_compare(Application::VERSION, '9.0.0', '<')
            || version_compare(Application::VERSION, '14.0.0', '>=')
        ) {
            return;
        }

        $resolver = $manager->connectors['database'] ?? null;
        if (! $resolver instanceof Closure
            || (new ReflectionFunction($resolver))->getClosureScopeClass()?->getName() !== QueueServiceProvider::class
        ) {
            return;
        }

        $manager->addConnector('database', static function () use ($resolver) {
            $connector = $resolver();

            return $connector::class === DatabaseConnector::class
                ? SqliteDatabaseConnector::fromDefault($connector)
                : $connector;
        });
    }
}
