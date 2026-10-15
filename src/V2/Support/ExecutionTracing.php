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

/** @internal */
final class ExecutionTracing
{
    private const FIELDS = [
        'workflow_instance_id', 'workflow_run_id', 'workflow_type', 'namespace',
        'task_id', 'activity_id', 'activity_type', 'activity_attempt_id',
        'activity_attempt_number', 'request_id',
    ];

    private static ?ContextKeyInterface $correlationKey = null;

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

        return is_array($fields) ? $fields : [];
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
            // A queue worker's ambient trace is not the run's causal authority.
            $parent = Context::getRoot();
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
            $version = InstalledVersions::isInstalled('durable-workflow/workflow')
                ? InstalledVersions::getPrettyVersion('durable-workflow/workflow')
                : null;
            $span = Globals::tracerProvider()->getTracer('durable-workflow/workflow', $version)
                ->spanBuilder($name)
                ->setSpanKind(SpanKind::KIND_CONSUMER)
                ->setParent($parent)
                ->setAttributes($fields)
                ->startSpan();
            if ($span->getContext()->isValid()) {
                $fields['trace_id'] = $span->getContext()->getTraceId();
                $fields['span_id'] = $span->getContext()->getSpanId();
            }
            self::$correlationKey ??= Context::createKey('durable-workflow.correlation');
            $scope = $span->storeInContext($parent)
                ->with(self::$correlationKey, $fields)->activate();
            self::enterFiberScope();
        } catch (Throwable) {
            self::close($scope, $span);

            return $operation();
        }

        try {
            return $operation();
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
