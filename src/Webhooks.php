<?php

declare(strict_types=1);

namespace Workflow;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Workflow\Auth\NullAuthenticator;
use Workflow\Auth\SignatureAuthenticator;
use Workflow\Auth\TokenAuthenticator;
use Workflow\Auth\WebhookAuthenticator;

class Webhooks
{
    public static function routes($customNamespace = null, $customAppPath = null)
    {
        $workflows_folder = config('workflows.workflows_folder', 'Workflows');
        $folder = $customAppPath ?? app_path($workflows_folder);
        $namespace = $customNamespace ?? "App\\{$workflows_folder}";
        $basePath = rtrim(config('workflows.webhooks_route', 'webhooks'), '/');

        foreach (self::discoverWorkflows($namespace, $folder) as $workflow) {
            self::registerWorkflowWebhooks($workflow, $basePath);
            self::registerSignalWebhooks($workflow, $basePath);
        }
    }

    private static function discoverWorkflows($namespace, $folder)
    {
        if (! is_dir($folder)) {
            return [];
        }

        $files = self::scanDirectory($folder);

        $filter = array_filter(
            array_map(static fn ($file) => self::getClassFromFile($file, $namespace, $folder), $files),
            static fn ($class) => is_subclass_of($class, \Workflow\Workflow::class)
        );

        return $filter;
    }

    private static function scanDirectory($directory)
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && Str::endsWith($file->getFilename(), '.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private static function getClassFromFile($file, $namespace, $basePath)
    {
        $relativePath = Str::replaceFirst($basePath . '/', '', $file);
        $classPath = str_replace(['/', '.php'], ['\\', ''], $relativePath);

        return "{$namespace}\\{$classPath}";
    }

    private static function registerWorkflowWebhooks($workflow, $basePath)
    {
        $reflection = new ReflectionClass($workflow);

        if (! self::hasWebhookAttributeOnClass($reflection)) {
            return;
        }

        foreach ($reflection->getMethods() as $method) {
            if ($method->getName() === 'execute') {
                $slug = Str::kebab(class_basename($workflow));
                Route::post("{$basePath}/start/{$slug}", static function (Request $request) use ($workflow) {
                    $request = self::validateAuth($request);
                    $params = self::resolveNamedParameters($workflow, 'execute', $request->all());
                    WorkflowStub::make($workflow)->start(...$params);
                    return response()->json([
                        'message' => 'Workflow started',
                    ]);
                })->name("workflows.start.{$slug}");
            }
        }
    }

    private static function registerSignalWebhooks($workflow, $basePath)
    {
        foreach (self::getSignalMethods($workflow) as $method) {
            if (self::hasWebhookAttributeOnMethod($method)) {
                $slug = Str::kebab(class_basename($workflow));
                $methodName = $method->getName();
                $signal = Str::kebab($methodName);
                Route::post(
                    "{$basePath}/signal/{$slug}/{workflowId}/{$signal}",
                    static function (Request $request, $workflowId) use ($workflow, $methodName) {
                        $request = self::validateAuth($request);
                        $workflowInstance = WorkflowStub::load($workflowId);
                        $params = self::resolveNamedParameters(
                            $workflow,
                            $methodName,
                            $request->except('workflowId')
                        );
                        $workflowInstance->{$methodName}(...$params);
                        return response()->json([
                            'message' => 'Signal sent',
                        ]);
                    }
                )->name("workflows.signal.{$slug}.{$signal}");
            }
        }
    }

    private static function getSignalMethods($workflow)
    {
        return array_filter(
            (new ReflectionClass($workflow))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn ($method) => count($method->getAttributes(\Workflow\SignalMethod::class)) > 0
        );
    }

    private static function hasWebhookAttributeOnClass(ReflectionClass $class): bool
    {
        return count($class->getAttributes(Webhook::class)) > 0;
    }

    private static function hasWebhookAttributeOnMethod(ReflectionMethod $method): bool
    {
        return count($method->getAttributes(Webhook::class)) > 0;
    }

    private static function resolveNamedParameters($class, $method, $payload)
    {
        $reflection = new ReflectionClass($class);
        $method = $reflection->getMethod($method);
        $params = [];

        foreach ($method->getParameters() as $param) {
            $name = $param->getName();
            if (array_key_exists($name, $payload)) {
                $params[$name] = $payload[$name];
            } elseif ($param->isDefaultValueAvailable()) {
                $params[$name] = $param->getDefaultValue();
            }
        }

        return $params;
    }

    private static function validateAuth(Request $request): Request
    {
        $authenticatorClass = match (config('workflows.webhook_auth.method', 'none')) {
            'none' => NullAuthenticator::class,
            'signature' => SignatureAuthenticator::class,
            'token' => TokenAuthenticator::class,
            'custom' => config('workflows.webhook_auth.custom.class'),
            default => null,
        };

        if (! is_subclass_of($authenticatorClass, WebhookAuthenticator::class)) {
            abort(401, 'Unauthorized');
        }

        return (new $authenticatorClass())->validate($request);
    }
}
