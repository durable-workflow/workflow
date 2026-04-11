<?php

declare(strict_types=1);

namespace Workflow\V2;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use LogicException;
use ReflectionNamedType;
use ReflectionParameter;
use Workflow\Auth\NullAuthenticator;
use Workflow\Auth\SignatureAuthenticator;
use Workflow\Auth\TokenAuthenticator;
use Workflow\Auth\WebhookAuthenticator;
use Workflow\V2\Enums\CommandOutcome;
use Workflow\V2\Enums\DuplicateStartPolicy;
use Workflow\V2\Support\CommandResponse;
use Workflow\V2\Support\EntryMethod;
use Workflow\V2\Support\HeartbeatProgress;
use Workflow\V2\Support\QueryResponse;
use Workflow\V2\Support\TypeRegistry;
use Workflow\V2\Support\UpdateWaitPolicy;
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
                $commandContext = self::commandContext($request);

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

        Route::post("{$basePath}/activity-tasks/{taskId}/claim", static function (
            Request $request,
            string $taskId,
        ) {
            $request = self::validateAuth($request);

            return self::activityTaskClaimResponse($taskId, self::resolveActivityLeaseOwner($request->all()));
        })->name('workflows.v2.activity-tasks.claim');

        Route::get("{$basePath}/activity-attempts/{attemptId}", static function (
            Request $request,
            string $attemptId,
        ) {
            $request = self::validateAuth($request);

            return self::activityAttemptStatusResponse(ActivityTaskBridge::status($attemptId));
        })->name('workflows.v2.activity-attempts.status');

        Route::post("{$basePath}/activity-attempts/{attemptId}/heartbeat", static function (
            Request $request,
            string $attemptId,
        ) {
            $request = self::validateAuth($request);

            return self::activityAttemptStatusResponse(
                ActivityTaskBridge::heartbeatStatus(
                    $attemptId,
                    self::resolveActivityHeartbeatProgress($request->all()),
                )
            );
        })->name('workflows.v2.activity-attempts.heartbeat');

        Route::post("{$basePath}/activity-attempts/{attemptId}/complete", static function (
            Request $request,
            string $attemptId,
        ) {
            $request = self::validateAuth($request);

            return self::activityAttemptOutcomeResponse(
                ActivityTaskBridge::complete($attemptId, self::resolveActivityResult($request->all())),
            );
        })->name('workflows.v2.activity-attempts.complete');

        Route::post("{$basePath}/activity-attempts/{attemptId}/fail", static function (
            Request $request,
            string $attemptId,
        ) {
            $request = self::validateAuth($request);

            return self::activityAttemptOutcomeResponse(
                ActivityTaskBridge::fail($attemptId, self::resolveActivityFailure($request->all())),
            );
        })->name('workflows.v2.activity-attempts.fail');

        Route::post("{$basePath}/instances/{workflowId}/runs/{runId}/queries/{query}", static function (
            Request $request,
            string $workflowId,
            string $runId,
            string $query,
        ) {
            $request = self::validateAuth($request);

            $response = QueryResponse::execute(
                self::selectionStub($workflowId, $runId),
                $query,
                self::resolveQueryArguments($request->all()),
                'run',
            );

            return response()->json($response['payload'], $response['status']);
        })->name('workflows.v2.runs.query');

        Route::post("{$basePath}/instances/{workflowId}/queries/{query}", static function (
            Request $request,
            string $workflowId,
            string $query,
        ) {
            $request = self::validateAuth($request);

            $response = QueryResponse::execute(
                self::selectionStub($workflowId),
                $query,
                self::resolveQueryArguments($request->all()),
                'instance',
            );

            return response()->json($response['payload'], $response['status']);
        })->name('workflows.v2.query');

        Route::post("{$basePath}/instances/{workflowId}/runs/{runId}/signals/{signal}", static function (
            Request $request,
            string $workflowId,
            string $runId,
            string $signal,
        ) {
            $request = self::validateAuth($request);

            $result = self::selectionStub($workflowId, $runId)
                ->withCommandContext(self::commandContext($request))
                ->attemptSignalWithArguments($signal, self::resolveSignalArguments($request->all()));

            return self::commandResponse($result, match ($result->outcome()) {
                CommandOutcome::RejectedUnknownSignal->value => 404,
                CommandOutcome::RejectedInvalidArguments->value => 422,
                default => $result->accepted() ? 202 : 409,
            });
        })->name('workflows.v2.runs.signal');

        Route::post("{$basePath}/instances/{workflowId}/signals/{signal}", static function (
            Request $request,
            string $workflowId,
            string $signal,
        ) {
            $request = self::validateAuth($request);

            $result = self::selectionStub($workflowId)
                ->withCommandContext(self::commandContext($request))
                ->attemptSignalWithArguments($signal, self::resolveSignalArguments($request->all()));

            return self::commandResponse($result, match ($result->outcome()) {
                CommandOutcome::RejectedUnknownSignal->value => 404,
                CommandOutcome::RejectedInvalidArguments->value => 422,
                default => $result->accepted() ? 202 : 409,
            });
        })->name('workflows.v2.signal');

        Route::post("{$basePath}/instances/{workflowId}/runs/{runId}/updates/{update}", static function (
            Request $request,
            string $workflowId,
            string $runId,
            string $update,
        ) {
            $request = self::validateAuth($request);

            $stub = self::selectionStub($workflowId, $runId)
                ->withCommandContext(self::commandContext($request));
            $submitAcceptedOnly = self::shouldSubmitUpdate($request->all());
            $stub = $submitAcceptedOnly
                ? $stub
                : $stub->withUpdateWaitTimeout(self::resolveUpdateWaitTimeout($request->all()));
            $result = $submitAcceptedOnly
                ? $stub->submitUpdateWithArguments($update, self::resolveUpdateArguments($request->all()))
                : $stub->attemptUpdateWithArguments($update, self::resolveUpdateArguments($request->all()));

            return self::commandResponse($result, match (true) {
                $result->outcome() === CommandOutcome::RejectedUnknownUpdate
->value => 404,
                $result->outcome() === CommandOutcome::RejectedInvalidArguments
->value => 422,
                $result->rejected() => 409,
                $result instanceof UpdateResult && $result->failed() => 422,
                $result instanceof UpdateResult && $result->updateStatus() === 'accepted' => 202,
                default => 200,
            });
        })->name('workflows.v2.runs.update');

        Route::post("{$basePath}/instances/{workflowId}/updates/{update}", static function (
            Request $request,
            string $workflowId,
            string $update,
        ) {
            $request = self::validateAuth($request);

            $stub = self::selectionStub($workflowId)
                ->withCommandContext(self::commandContext($request));
            $submitAcceptedOnly = self::shouldSubmitUpdate($request->all());
            $stub = $submitAcceptedOnly
                ? $stub
                : $stub->withUpdateWaitTimeout(self::resolveUpdateWaitTimeout($request->all()));
            $result = $submitAcceptedOnly
                ? $stub->submitUpdateWithArguments($update, self::resolveUpdateArguments($request->all()))
                : $stub->attemptUpdateWithArguments($update, self::resolveUpdateArguments($request->all()));

            return self::commandResponse($result, match (true) {
                $result->outcome() === CommandOutcome::RejectedUnknownUpdate
->value => 404,
                $result->outcome() === CommandOutcome::RejectedInvalidArguments
->value => 422,
                $result->rejected() => 409,
                $result instanceof UpdateResult && $result->failed() => 422,
                $result instanceof UpdateResult && $result->updateStatus() === 'accepted' => 202,
                default => 200,
            });
        })->name('workflows.v2.update');

        Route::get("{$basePath}/instances/{workflowId}/runs/{runId}/updates/{updateId}", static function (
            Request $request,
            string $workflowId,
            string $runId,
            string $updateId,
        ) {
            $request = self::validateAuth($request);

            try {
                $result = self::selectionStub($workflowId, $runId)->inspectUpdate($updateId);
            } catch (LogicException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 404);
            }

            return self::updateLookupResponse($result);
        })->name('workflows.v2.runs.update-status');

        Route::get("{$basePath}/instances/{workflowId}/updates/{updateId}", static function (
            Request $request,
            string $workflowId,
            string $updateId,
        ) {
            $request = self::validateAuth($request);

            try {
                $result = self::selectionStub($workflowId)->inspectUpdate($updateId);
            } catch (LogicException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 404);
            }

            return self::updateLookupResponse($result);
        })->name('workflows.v2.update-status');

        Route::post(
            "{$basePath}/instances/{workflowId}/runs/{runId}/repair",
            static function (Request $request, string $workflowId, string $runId) {
                $request = self::validateAuth($request);

                $result = self::selectionStub($workflowId, $runId)
                    ->withCommandContext(self::commandContext($request))
                    ->attemptRepair();

                return self::commandResponse($result, $result->accepted() ? 200 : 409);
            }
        )->name('workflows.v2.runs.repair');

        Route::post(
            "{$basePath}/instances/{workflowId}/repair",
            static function (Request $request, string $workflowId) {
                $request = self::validateAuth($request);

                $result = self::selectionStub($workflowId)
                    ->withCommandContext(self::commandContext($request))
                    ->attemptRepair();

                return self::commandResponse($result, $result->accepted() ? 200 : 409);
            }
        )->name('workflows.v2.repair');

        Route::post(
            "{$basePath}/instances/{workflowId}/runs/{runId}/cancel",
            static function (Request $request, string $workflowId, string $runId) {
                $request = self::validateAuth($request);

                $result = self::selectionStub($workflowId, $runId)
                    ->withCommandContext(self::commandContext($request))
                    ->attemptCancel();

                return self::commandResponse($result, $result->accepted() ? 200 : 409);
            }
        )->name('workflows.v2.runs.cancel');

        Route::post(
            "{$basePath}/instances/{workflowId}/cancel",
            static function (Request $request, string $workflowId) {
                $request = self::validateAuth($request);

                $result = self::selectionStub($workflowId)
                    ->withCommandContext(self::commandContext($request))
                    ->attemptCancel();

                return self::commandResponse($result, $result->accepted() ? 200 : 409);
            }
        )->name('workflows.v2.cancel');

        Route::post(
            "{$basePath}/instances/{workflowId}/runs/{runId}/terminate",
            static function (Request $request, string $workflowId, string $runId) {
                $request = self::validateAuth($request);

                $result = self::selectionStub($workflowId, $runId)
                    ->withCommandContext(self::commandContext($request))
                    ->attemptTerminate();

                return self::commandResponse($result, $result->accepted() ? 200 : 409);
            }
        )->name('workflows.v2.runs.terminate');

        Route::post(
            "{$basePath}/instances/{workflowId}/terminate",
            static function (Request $request, string $workflowId) {
                $request = self::validateAuth($request);

                $result = self::selectionStub($workflowId)
                    ->withCommandContext(self::commandContext($request))
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
        $visibility = $payload['visibility'] ?? [];

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

        if (! is_array($visibility)) {
            throw ValidationException::withMessages([
                'visibility' => ['The visibility field must be an object.'],
            ]);
        }

        $businessKey = $visibility['business_key'] ?? null;
        $labels = $visibility['labels'] ?? [];

        if ($businessKey !== null && ! is_string($businessKey)) {
            throw ValidationException::withMessages([
                'visibility.business_key' => ['The visibility.business_key field must be a string.'],
            ]);
        }

        if (! is_array($labels)) {
            throw ValidationException::withMessages([
                'visibility.labels' => ['The visibility.labels field must be an object.'],
            ]);
        }

        try {
            $startOptions = new StartOptions($duplicateStartPolicy, $businessKey, $labels);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'visibility' => [$exception->getMessage()],
            ]);
        }

        unset($payload['workflow_id'], $payload['on_duplicate'], $payload['visibility']);

        $arguments = [];
        $missing = [];

        try {
            $method = EntryMethod::forWorkflow($workflow);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'workflow' => [$exception->getMessage()],
            ]);
        }

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

        return [$instanceId, $arguments, $startOptions];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int|string, mixed>
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

        return array_is_list($arguments)
            ? array_values($arguments)
            : $arguments;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int|string, mixed>
     */
    private static function resolveQueryArguments(array $payload): array
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

        return array_is_list($arguments)
            ? array_values($arguments)
            : $arguments;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int|string, mixed>
     */
    private static function resolveUpdateArguments(array $payload): array
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

        return array_is_list($arguments)
            ? array_values($arguments)
            : $arguments;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function shouldSubmitUpdate(array $payload): bool
    {
        return UpdateWaitPolicy::shouldSubmitAcceptedOnly($payload['wait_for'] ?? null);
    }

    private static function resolveUpdateWaitTimeout(array $payload): ?int
    {
        return UpdateWaitPolicy::requestedTimeoutSeconds($payload['wait_timeout_seconds'] ?? null);
    }

    private static function resolveActivityLeaseOwner(array $payload): ?string
    {
        if (! array_key_exists('lease_owner', $payload)) {
            return null;
        }

        $leaseOwner = $payload['lease_owner'];

        if (! is_string($leaseOwner)) {
            throw ValidationException::withMessages([
                'lease_owner' => ['The lease_owner field must be a non-empty string up to 255 characters.'],
            ]);
        }

        $leaseOwner = trim($leaseOwner);

        if ($leaseOwner === '' || mb_strlen($leaseOwner) > 255) {
            throw ValidationException::withMessages([
                'lease_owner' => ['The lease_owner field must be a non-empty string up to 255 characters.'],
            ]);
        }

        return $leaseOwner;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function resolveActivityHeartbeatProgress(array $payload): array
    {
        if (! array_key_exists('progress', $payload)) {
            return [];
        }

        $progress = $payload['progress'];

        if (! is_array($progress) || ($progress !== [] && array_is_list($progress))) {
            throw ValidationException::withMessages([
                'progress' => ['The progress field must be an object.'],
            ]);
        }

        try {
            HeartbeatProgress::normalizeForWrite($progress);
        } catch (LogicException $exception) {
            throw ValidationException::withMessages([
                'progress' => [$exception->getMessage()],
            ]);
        }

        return $progress;
    }

    private static function resolveActivityResult(array $payload): mixed
    {
        return $payload['result'] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|string
     */
    private static function resolveActivityFailure(array $payload): array|string
    {
        if (! array_key_exists('failure', $payload)) {
            throw ValidationException::withMessages([
                'failure' => ['The failure field is required and must be a string or object.'],
            ]);
        }

        $failure = $payload['failure'];

        if (is_string($failure)) {
            return $failure;
        }

        if (! is_array($failure) || ($failure !== [] && array_is_list($failure))) {
            throw ValidationException::withMessages([
                'failure' => ['The failure field must be a string or object.'],
            ]);
        }

        return $failure;
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

    private static function commandContext(Request $request): CommandContext
    {
        return CommandContext::webhook($request, (string) config('workflows.webhook_auth.method', 'none'));
    }

    private static function selectionStub(string $workflowId, ?string $runId = null): WorkflowStub
    {
        return $runId === null
            ? WorkflowStub::load($workflowId)
            : WorkflowStub::loadSelection($workflowId, $runId);
    }

    private static function commandResponse(CommandResult $result, int $status, ?string $workflowType = null)
    {
        return response()->json(CommandResponse::payload($result, $workflowType), $status);
    }

    private static function updateLookupResponse(UpdateResult $result)
    {
        return response()->json(
            CommandResponse::payload($result),
            $result->updateStatus() === 'accepted' ? 202 : 200,
        );
    }

    private static function activityTaskClaimResponse(string $taskId, ?string $leaseOwner)
    {
        $payload = ActivityTaskBridge::claimStatus($taskId, $leaseOwner);
        $status = match ($payload['reason']) {
            null => 200,
            'task_not_found' => 404,
            default => 409,
        };

        $response = response()->json($payload, $status);

        if (is_int($payload['retry_after_seconds'] ?? null)) {
            $response->header('Retry-After', (string) $payload['retry_after_seconds']);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function activityAttemptStatusResponse(array $payload)
    {
        $status = ($payload['reason'] ?? null) === 'attempt_not_found'
            ? 404
            : 200;

        return response()->json($payload, $status);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function activityAttemptOutcomeResponse(array $payload)
    {
        $status = match ($payload['reason'] ?? null) {
            null => 200,
            'attempt_not_found' => 404,
            default => 409,
        };

        return response()->json($payload, $status);
    }
}
