<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Composer\InstalledVersions;
use Fiber;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextKeyInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;
use WeakMap;
use Workflow\V2\CommandResult;
use Workflow\V2\Enums\CommandStatus;
use Workflow\V2\Enums\CommandType;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\SignalWithStartResult;
use Workflow\V2\StartResult;

/** @internal */
final class ExecutionTracing
{
    private const FIELDS = [
        'workflow_instance_id', 'workflow_run_id', 'workflow_type', 'namespace',
        'task_id', 'activity_id', 'activity_type', 'activity_attempt_id',
        'activity_attempt_number', 'request_id',
        'timer_id',
        'workflow_command_id',
    ];

    private const TIMINGS = [
        'durable.task.queue_wait_seconds', 'durable.task.retry_backoff_seconds',
        'durable.timer.requested_wait_seconds', 'durable.timer.lateness_seconds',
    ];

    private static ?ContextKeyInterface $correlationKey = null;

    private static ?ContextKeyInterface $linkKey = null;

    /**
     * @var WeakMap<Fiber, int>|null
     */
    private static ?WeakMap $activeFibers = null;

    public static function bind(callable $callback): callable
    {
        try {
            if (self::current() === []) {
                return $callback;
            }
            $context = Context::getCurrent();
        } catch (Throwable) {
            return $callback;
        }

        // Attach inside the new Fiber. Its scope must also unwind inside that Fiber.
        return static function (mixed ...$arguments) use ($callback, $context): mixed {
            $scope = null;
            try {
                $scope = $context->activate();
                self::enterFiberScope();
            } catch (Throwable) {
            }
            try {
                return $callback(...$arguments);
            } finally {
                self::close($scope, null);
            }
        };
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $baggageAllowlist
     */
    public static function activity(
        array $attributes,
        mixed $persistedContext,
        callable $operation,
        array $baggageAllowlist = [],
        bool $enabled = false,
    ): mixed {
        return self::segment(
            'durable.activity.attempt',
            $attributes,
            $persistedContext,
            $operation,
            $baggageAllowlist,
            $enabled
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $baggageAllowlist
     */
    public static function workflow(
        array $attributes,
        mixed $persistedContext,
        callable $operation,
        array $baggageAllowlist = [],
        bool $enabled = false,
    ): mixed {
        return self::segment(
            'durable.workflow.task',
            $attributes,
            $persistedContext,
            $operation,
            $baggageAllowlist,
            $enabled
        );
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $baggageAllowlist
     */
    public static function timer(
        array $attributes,
        mixed $persistedContext,
        callable $operation,
        array $baggageAllowlist = [],
        bool $enabled = false,
    ): mixed {
        return self::segment(
            'durable.timer.task',
            $attributes,
            $persistedContext,
            $operation,
            $baggageAllowlist,
            $enabled
        );
    }

    /**
     * @return array<string, string|int>
     */
    public static function current(): array
    {
        if (self::$correlationKey === null || ! class_exists(Context::class)) {
            return [];
        }

        // The SDK's compatibility fallback to the main Fiber is not a durable binding.
        $fiber = Fiber::getCurrent();
        if ($fiber !== null && ! isset(self::$activeFibers[$fiber])) {
            return [];
        }

        $fields = Context::getCurrent()->get(self::$correlationKey);
        if (! is_array($fields)) {
            return [];
        }
        // Application instrumentation may activate a child span inside this durable scope.
        unset($fields['trace_id'], $fields['span_id']);
        $spanContext = Span::getCurrent()->getContext();
        if ($spanContext->isValid()) {
            $fields['trace_id'] = $spanContext->getTraceId();
            $fields['span_id'] = $spanContext->getSpanId();
        }

        return $fields;
    }

    public static function admission(WorkflowInstance $instance, callable $operation, bool $enabled = false): mixed
    {
        if (! $enabled || ! class_exists(Globals::class)) {
            return $operation();
        }
        $attributes = [];
        try {
            $attributes = [
                'workflow_instance_id' => $instance->getRawOriginal('id'),
                'workflow_type' => $instance->getRawOriginal('workflow_type'),
                'namespace' => $instance->getRawOriginal('namespace'),
            ];
        } catch (Throwable) {
            // Optional identity reads do not replace admission.
        }

        return self::segment('durable.workflow.admission', $attributes, null, $operation, [], true);
    }

    public static function recordFailure(Throwable $error): void
    {
        try {
            if (self::current() === []) {
                return;
            }
            Span::getCurrent()->setStatus(StatusCode::STATUS_ERROR)
                ->setAttribute('error.type', $error::class);
        } catch (Throwable) {
            // Telemetry cannot replace the application's original exception.
        }
    }

    public static function linkSignal(WorkflowRun $run, WorkflowCommand $command): void
    {
        self::linkCommand($run, $command, CommandType::Signal);
    }

    public static function linkUpdate(WorkflowRun $run, WorkflowCommand $command): void
    {
        self::linkCommand($run, $command, CommandType::Update);
    }

    public static function linkCancellation(WorkflowRun $run): void
    {
        try {
            // Execution already owns this loaded snapshot. Telemetry adds no database read.
            if (! $run->relationLoaded('commands')) {
                return;
            }
            $command = $run->commands->firstWhere('id', $run->cancellation_request_command_id);
            if ($command instanceof WorkflowCommand) {
                self::linkCommand($run, $command, CommandType::RequestCancellation);
            }
        } catch (Throwable) {
            // Optional metadata cannot interrupt cancellation delivery.
        }
    }

    private static function linkCommand(WorkflowRun $run, WorkflowCommand $command, CommandType $type): void
    {
        try {
            $fields = self::current();
            if (self::$linkKey === null || ($fields['workflow_run_id'] ?? null) !== $run->id
                || ($fields['namespace'] ?? null) !== $run->namespace
                || $command->workflow_run_id !== $run->id
                || $command->workflow_instance_id !== $run->workflow_instance_id
                || $command->command_type !== $type || $command->status !== CommandStatus::Accepted) {
                return;
            }
            $state = Context::getCurrent()->get(self::$linkKey);
            if (! $state instanceof TraceLinkState || ! $state->isRecording()) {
                return;
            }
            $carrier = TraceCarrier::fromCommand($command);
            if ($carrier === null) {
                return;
            }
            $context = TraceContextPropagator::getInstance()->extract($carrier['carrier'], context: Context::getRoot());
            $state->add(Span::fromContext($context)->getContext(), [
                'workflow_command_id' => $command->id,
                'command_type' => $type->value,
            ]);
        } catch (Throwable) {
            // Optional causal links cannot replace the command's durable decision.
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $baggageAllowlist
     */
    private static function segment(
        string $name,
        array $attributes,
        mixed $persistedContext,
        callable $operation,
        array $baggageAllowlist,
        bool $enabled,
    ): mixed {
        if (! $enabled || ! class_exists(Globals::class)) {
            return $operation();
        }

        $span = null;
        $scope = null;
        try {
            // Local admission keeps the caller's context. Workers recover only stored authority.
            $parent = $name === 'durable.workflow.admission' ? Context::getCurrent() : Context::getRoot();
            $carrier = TraceCarrier::normalize($persistedContext, $baggageAllowlist);
            if ($carrier !== null) {
                $parent = TraceContextPropagator::getInstance()->extract($carrier['carrier'], context: $parent);
                $parent = BaggagePropagator::getInstance()->extract($carrier['carrier'], context: $parent);
            }
            // Preserve application providers without inheriting unrelated worker context.
            $parent = Configurator::create()
                ->withTracerProvider(Globals::tracerProvider())
                ->withMeterProvider(Globals::meterProvider())
                ->withLoggerProvider(Globals::loggerProvider())
                ->withPropagator(Globals::propagator())
                ->withResponsePropagator(Globals::responsePropagator())
                ->storeInContext($parent);
            $fields = self::fields($attributes);
            $spanAttributes = $fields;
            foreach (self::TIMINGS as $key) {
                $value = $attributes[$key] ?? null;
                if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0) {
                    $spanAttributes[$key] = $value;
                }
            }
            $version = InstalledVersions::isInstalled('durable-workflow/workflow')
                ? InstalledVersions::getPrettyVersion('durable-workflow/workflow')
                : null;
            $span = Globals::tracerProvider()->getTracer('durable-workflow/workflow', $version)
                ->spanBuilder($name)
                ->setSpanKind(
                    $name === 'durable.workflow.admission' ? SpanKind::KIND_INTERNAL : SpanKind::KIND_CONSUMER
                )
                ->setParent($parent)
                ->setAttributes($spanAttributes)
                ->startSpan();
            if ($span->getContext()->isValid()) {
                $fields['trace_id'] = $span->getContext()->getTraceId();
                $fields['span_id'] = $span->getContext()->getSpanId();
            }
            self::$correlationKey ??= Context::createKey('durable-workflow.correlation');
            $active = $span->storeInContext($parent)
                ->with(self::$correlationKey, $fields);
            if ($name === 'durable.workflow.task') {
                self::$linkKey ??= Context::createKey('durable-workflow.trace-links');
                $active = $active->with(self::$linkKey, new TraceLinkState($span));
            }
            $scope = $active->activate();
            self::enterFiberScope();
        } catch (Throwable) {
            self::close($scope, $span);

            return $operation();
        }

        try {
            $result = $operation();
            if ($name === 'durable.workflow.admission'
                && ($result instanceof StartResult || $result instanceof SignalWithStartResult) && $span !== null) {
                try {
                    self::recordAdmissionResult($span, $result);
                } catch (Throwable) {
                    // A telemetry receipt read cannot replace the committed decision.
                }
            }

            return $result;
        } catch (Throwable $error) {
            self::recordFailure($error);
            throw $error;
        } finally {
            self::close($scope, $span);
        }
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, string|int>
     */
    private static function fields(array $attributes): array
    {
        $fields = [];
        foreach (self::FIELDS as $key) {
            $value = $attributes[$key] ?? null;
            if (($key === 'activity_attempt_number' && is_int($value) && $value > 0)
                || ($key !== 'activity_attempt_number' && is_string($value) && $value !== '' && strlen($value) <= 512
                    && ! preg_match('/[\x00-\x1f\x7f]/', $value))) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    private static function recordAdmissionResult(SpanInterface $span, CommandResult $result): void
    {
        $attributes = self::fields([
            'workflow_instance_id' => $result->instanceId(),
            'workflow_run_id' => $result->runId(),
            'workflow_type' => $result->workflowType(),
            'workflow_command_id' => $result->commandId(),
        ]);
        $decisions = [
            'durable.command.type' => $result->type(),
            'durable.command.status' => $result->status(),
            'durable.command.outcome' => $result->outcome(),
            'durable.command.rejection_reason' => $result->rejectionReason(),
        ];
        if ($result instanceof SignalWithStartResult) {
            $decisions += [
                'durable.start.command_id' => $result->startCommandId(),
                'durable.start.status' => $result->startStatus(),
                'durable.start.outcome' => $result->startOutcome(),
            ];
        }
        foreach ($decisions as $key => $value) {
            if (is_string($value) && $value !== '' && strlen($value) <= 256
                && ! preg_match('/[\x00-\x1f\x7f]/', $value)) {
                $attributes[$key] = $value;
            }
        }
        $span->setAttributes($attributes);
    }

    private static function close(?ScopeInterface $scope, ?SpanInterface $span): void
    {
        try {
            $scope?->detach();
        } catch (Throwable) {
        }
        $fiber = Fiber::getCurrent();
        if ($scope !== null && $fiber !== null && isset(self::$activeFibers[$fiber])) {
            if (--self::$activeFibers[$fiber] === 0) {
                unset(self::$activeFibers[$fiber]);
            }
        }
        try {
            // The application owns buffering and provider shutdown. Never flush per task.
            $span?->end();
        } catch (Throwable) {
        }
    }

    private static function enterFiberScope(): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            self::$activeFibers ??= new WeakMap();
            self::$activeFibers[$fiber] = (self::$activeFibers[$fiber] ?? 0) + 1;
        }
    }
}
