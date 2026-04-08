<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use LogicException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Workflow\Auth\NullAuthenticator;
use Workflow\Auth\SignatureAuthenticator;
use Workflow\Auth\TokenAuthenticator;
use Workflow\Auth\WebhookAuthenticator;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\DuplicateStartPolicy;
use Workflow\V2\Support\CommandResponse;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\WorkflowInstanceId;

final class Webhooks
{
    /**
     * @param array<int|string, class-string<Workflow>> $workflows
     */
    public static function routes(array $workflows, ?string $basePath = null): void
    {
        $basePath = rtrim($basePath ?? config('workflows.webhooks_route', 'webhooks'), '/');

        foreach (self::normalizeWorkflows($workflows) as $alias => $workflow) {
            Route::post("{$basePath}/start/{$alias}", static function (Request $request) use ($alias, $workflow) {
                $request = self::validateAuth($request);
                $commandContext = CommandContext::webhook(
                    $request,
                    (string) config('workflows.webhook_auth.method', 'none'),
                );

                [$instanceId, $arguments, $startOptions] = self::resolveStartArguments($workflow, $request->all());

                $stub = WorkflowStub::make($workflow, $instanceId)->withCommandContext($commandContext);
                $result = $stub->attemptStart(...[...$arguments, $startOptions]);

                return self::commandResponse($result, match ($result->outcome()) {
                    CommandOutcome::ReturnedExistingActive->value => 200,
                    CommandOutcome::StartedNew->value => 202,
                    default => 409,
                }, TypeRegistry::for($workflow));
            })->name("workflows.v2.start.{$alias}");
        }

        Route::post(
            "{$basePath}/instances/{workflowId}/cancel",
            static function (Request $request, string $workflowId) {
                $request = self::validateAuth($request);

                $result = WorkflowStub::load($workflowId)
                    ->withCommandContext(CommandContext::webhook(
                        $request,
                        (string) config('workflows.webhook_auth.method', 'none'),
                    ))
                    ->attemptCancel();

                return self::commandResponse($result, $result->accepted() ? 200 : 409);
            }
        )->name('workflows.v2.cancel');

        Route::post(
            "{$basePath}/instances/{workflowId}/repair",
            static function (Request $request, string $workflowId) {
                $request = self::validateAuth($request);

                $result = WorkflowStub::load($workflowId)
                    ->withCommandContext(CommandContext::webhook(
                        $request,
                        (string) config('workflows.webhook_auth.method', 'none'),
                    ))
                    ->attemptRepair();

                return self::commandResponse($result, $result->accepted() ? 200 : 409);
            }
        )->name('workflows.v2.repair');

        Route::post("{$basePath}/instances/{workflowId}/signals/{signal}", static function (
            Request $request,
            string $workflowId,
            string $signal,
        ) {
            $request = self::validateAuth($request);

            $result = WorkflowStub::load($workflowId)
                ->withCommandContext(CommandContext::webhook(
                    $request,
                    (string) config('workflows.webhook_auth.method', 'none'),
                ))
                ->attemptSignal($signal, ...self::resolveSignalArguments($request->all()));

            return self::commandResponse($result, match ($result->outcome()) {
                CommandOutcome::RejectedUnknownSignal->value => 404,
                default => $result->accepted() ? 202 : 409,
            });
        })->name('workflows.v2.signal');

        Route::post("{$basePath}/instances/{workflowId}/updates/{update}", static function (
            Request $request,
            string $workflowId,
            string $update,
        ) {
            $request = self::validateAuth($request);

            $result = WorkflowStub::load($workflowId)
                ->withCommandContext(CommandContext::webhook(
                    $request,
                    (string) config('workflows.webhook_auth.method', 'none'),
                ))
                ->attemptUpdate($update, ...self::resolveSignalArguments($request->all()));

            return self::commandResponse($result, match (true) {
                $result->outcome() === CommandOutcome::RejectedUnknownUpdate
->value => 404,
                $result->rejected() => 409,
                $result instanceof UpdateResult && $result->failed() => 422,
                default => 200,
            });
        })->name('workflows.v2.update');

        Route::post(
            "{$basePath}/instances/{workflowId}/terminate",
            static function (Request $request, string $workflowId) {
                $request = self::validateAuth($request);

                $result = WorkflowStub::load($workflowId)
                    ->withCommandContext(CommandContext::webhook(
                        $request,
                        (string) config('workflows.webhook_auth.method', 'none'),
                    ))
                    ->attemptTerminate();

                return self::commandResponse($result, $result->accepted() ? 200 : 409);
            }
        )->name('workflows.v2.terminate');
    }

    /**
     * @param array<int|string, class-string<Workflow>> $workflows
     * @return array<string, class-string<Workflow>>
     */
    private static function normalizeWorkflows(array $workflows): array
    {
        $normalized = [];

        foreach ($workflows as $alias => $workflow) {
            if (! is_string($workflow) || ! is_subclass_of($workflow, Workflow::class)) {
                throw new LogicException(sprintf(
                    'Webhook workflow [%s] must extend %s.',
                    (string) $workflow,
                    Workflow::class
                ));
            }

            $resolvedAlias = is_int($alias)
                ? self::inferredAlias($workflow)
                : $alias;

            if (preg_match('/^[A-Za-z0-9._-]+$/', $resolvedAlias) !== 1) {
                throw new LogicException(sprintf(
                    'Webhook alias [%s] for workflow [%s] contains unsupported characters.',
                    $resolvedAlias,
                    $workflow,
                ));
            }

            $normalized[$resolvedAlias] = $workflow;
        }

        return $normalized;
    }

    /**
     * @param class-string<Workflow> $workflow
     * @return array{0: ?string, 1: array<int, mixed>, 2: StartOptions}
     */
    private static function resolveStartArguments(string $workflow, array $payload): array
    {
        $hasInstanceId = array_key_exists('workflow_id', $payload);
        $instanceId = $payload['workflow_id'] ?? null;
        $onDuplicate = $payload['on_duplicate'] ?? DuplicateStartPolicy::RejectDuplicate->value;

        if ($hasInstanceId && ! is_string($instanceId)) {
            throw ValidationException::withMessages([
                'workflow_id' => [WorkflowInstanceId::validationMessage('workflow_id')],
            ]);
        }

        if (is_string($instanceId)) {
            try {
                WorkflowInstanceId::assertValid($instanceId);
            } catch (LogicException) {
                throw ValidationException::withMessages([
                    'workflow_id' => [WorkflowInstanceId::validationMessage('workflow_id')],
                ]);
            }
        }

        if (! is_string($onDuplicate)) {
            throw ValidationException::withMessages([
                'on_duplicate' => ['The on_duplicate field must be a string.'],
            ]);
        }

        try {
            $duplicateStartPolicy = DuplicateStartPolicy::from($onDuplicate);
        } catch (\ValueError) {
            throw ValidationException::withMessages([
                'on_duplicate' => ['The selected on_duplicate value is invalid.'],
            ]);
        }

        unset($payload['workflow_id'], $payload['on_duplicate']);

        $arguments = [];
        $missing = [];
        $method = new ReflectionMethod($workflow, 'execute');

        foreach ($method->getParameters() as $parameter) {
            if (self::isContainerInjected($parameter)) {
                continue;
            }

            $name = $parameter->getName();

            if (array_key_exists($name, $payload)) {
                $arguments[] = $payload[$name];

                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            $missing[$name] = ["The {$name} field is required."];
        }

        if ($missing !== []) {
            throw ValidationException::withMessages($missing);
        }

        return [$instanceId, $arguments, new StartOptions($duplicateStartPolicy)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, mixed>
     */
    private static function resolveSignalArguments(array $payload): array
    {
        if (! array_key_exists('arguments', $payload)) {
            return [];
        }

        $arguments = $payload['arguments'];

        if (! is_array($arguments)) {
            throw ValidationException::withMessages([
                'arguments' => ['The arguments field must be an array.'],
            ]);
        }

        return array_values($arguments);
    }

    /**
     * @param class-string<Workflow> $workflow
     */
    private static function inferredAlias(string $workflow): string
    {
        $type = TypeRegistry::for($workflow);

        if ($type === $workflow) {
            throw new LogicException(sprintf(
                'Workflow [%s] must define a #[Type(...)] attribute or be registered with an explicit webhook alias.',
                $workflow,
            ));
        }

        return $type;
    }

    private static function isContainerInjected(ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof ReflectionNamedType
            && ! $type->isBuiltin();
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

    private static function commandResponse(CommandResult $result, int $status, ?string $workflowType = null)
    {
        return response()->json(CommandResponse::payload($result, $workflowType), $status);
    }
}
