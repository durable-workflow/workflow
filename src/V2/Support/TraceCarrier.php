<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Fiber;
use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Baggage\Propagation\BaggagePropagator;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextKeyInterface;
use Throwable;
use WeakMap;
use Workflow\V2\Models\WorkflowCommand;
use Workflow\V2\Models\WorkflowRun;

/**
 * Bounded telemetry metadata, kept separate from business payloads and authority.
 *
 * @internal
 */
final class TraceCarrier
{
    private const SCHEMA = 'durable-workflow.trace-context';

    private const LIMITS = [
        'traceparent' => 256,
        'tracestate' => 512,
        'baggage' => 2048,
    ];

    private static ?ContextKeyInterface $admissionKey = null;

    /**
     * @var WeakMap<Fiber, int>|null
     */
    private static ?WeakMap $admissionFibers = null;

    public static function withPreparedAdmission(callable $operation): mixed
    {
        $fiber = Fiber::getCurrent();
        try {
            self::$admissionKey ??= Context::createKey('durable-workflow.schedule-admission');
            $carrier = self::captureConfigured($diagnostic);
            $scope = Context::getCurrent()->with(self::$admissionKey, [
                'carrier' => $carrier,
                'diagnostic' => $diagnostic,
                'fiber' => $fiber,
            ])->activate();
            if ($fiber !== null) {
                self::$admissionFibers ??= new WeakMap();
                self::$admissionFibers[$fiber] = (self::$admissionFibers[$fiber] ?? 0) + 1;
            }
        } catch (Throwable) {
            return $operation();
        }

        try {
            return $operation();
        } finally {
            try {
                $scope->detach();
            } catch (Throwable) {
                // Optional carrier scope cleanup cannot replace the business result.
            } finally {
                if ($fiber !== null && isset(self::$admissionFibers[$fiber])) {
                    $depth = self::$admissionFibers[$fiber] - 1;
                    if ($depth === 0) {
                        unset(self::$admissionFibers[$fiber]);
                    } else {
                        self::$admissionFibers[$fiber] = $depth;
                    }
                }
            }
        }
    }

    public static function hasPreparedAdmission(): bool
    {
        return self::preparedAdmission() !== null;
    }

    public static function preparedAdmissionDiagnostic(): ?string
    {
        return self::preparedAdmission()['diagnostic'] ?? null;
    }

    /**
     * @return array{schema: string, version: int, carrier: array<string, string>}|null
     */
    public static function admissionParent(?string &$diagnostic = null): ?array
    {
        $prepared = self::preparedAdmission();
        if ($prepared !== null) {
            $diagnostic = $prepared['diagnostic'];

            return $prepared['carrier'];
        }

        return self::captureConfigured($diagnostic);
    }

    /**
     * @return array{schema: string, version: int, carrier: array<string, string>}|null
     */
    public static function captureConfigured(?string &$diagnostic = null): ?array
    {
        $diagnostic = null;
        try {
            if (! ExecutionTracing::enabled()) {
                return null;
            }
            $carrier = self::capture(ExecutionTracing::baggageAllowlist(), $diagnostic);
            ExecutionTracing::recordCarrierDiagnostic($diagnostic);

            return $carrier;
        } catch (Throwable) {
            $diagnostic = 'discarded';

            return null;
        }
    }

    /**
     * @return array{schema: string, version: int, carrier: array<string, string>}|null
     */
    public static function fromCommand(WorkflowCommand $command, ?string &$diagnostic = null): ?array
    {
        $diagnostic = null;
        try {
            $stored = array_key_exists('observability_context', $command->getAttributes())
                ? $command->getAttribute('observability_context') : null;

            // A link never imports the triggering caller's baggage into execution.
            return self::normalize($stored, diagnostic: $diagnostic);
        } catch (Throwable) {
            $diagnostic = 'discarded';

            return null;
        }
    }

    /**
     * @return array{schema: string, version: int, carrier: array<string, string>}|null
     */
    public static function fromRun(WorkflowRun $run, ?string &$diagnostic = null): ?array
    {
        $diagnostic = null;
        try {
            // Emission can be disabled without erasing a previously captured admission parent.
            $stored = array_key_exists('observability_context', $run->getAttributes())
                ? $run->getAttribute('observability_context') : null;
            if ($stored === null) {
                return null;
            }
            return self::normalize($stored, ExecutionTracing::baggageAllowlist(), $diagnostic);
        } catch (Throwable) {
            // Optional telemetry metadata must not interrupt child admission or retries.
            $diagnostic = 'discarded';

            return null;
        }
    }

    /**
     * @param list<string> $baggageAllowlist
     * @return array{schema: string, version: int, carrier: array<string, string>}|null
     */
    public static function capture(array $baggageAllowlist = [], ?string &$diagnostic = null): ?array
    {
        $diagnostic = null;
        if (! class_exists(Globals::class)) {
            return null;
        }

        try {
            $carrier = [];
            Globals::propagator()->inject($carrier);

            // Applications may also inject unrelated propagation formats.
            $carrier = array_intersect_key($carrier, self::LIMITS);
            if ($carrier === []) {
                return null;
            }

            return self::normalize([
                'schema' => self::SCHEMA,
                'version' => 1,
                'carrier' => $carrier,
            ], $baggageAllowlist, $diagnostic);
        } catch (Throwable) {
            $diagnostic = 'discarded';

            return null;
        }
    }

    /**
     * @param list<string> $baggageAllowlist
     * @return array{schema: string, version: int, carrier: array<string, string>}|null
     */
    public static function normalize(
        mixed $value,
        array $baggageAllowlist = [],
        ?string &$diagnostic = null,
    ): ?array {
        $diagnostic = $value === null ? null : 'discarded';
        if (! is_array($value) || ($value['schema'] ?? null) !== self::SCHEMA
            || ($value['version'] ?? null) !== 1 || ! is_array($value['carrier'] ?? null)
            || array_diff(array_keys($value), ['schema', 'version', 'carrier']) !== []
            || ! class_exists(TraceContextPropagator::class)) {
            return null;
        }

        $carrier = $value['carrier'];
        if (! isset($carrier['traceparent']) || array_diff(array_keys($carrier), array_keys(self::LIMITS)) !== []) {
            return null;
        }
        foreach ($carrier as $key => $header) {
            if (! is_string($header) || strlen($header) > self::LIMITS[$key]) {
                return null;
            }
        }

        try {
            if (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 4096) {
                return null;
            }

            // Invalid metadata must never fall back to a worker's ambient span.
            $context = TraceContextPropagator::getInstance()->extract($carrier, context: Context::getRoot());
            if (! Span::fromContext($context)->getContext()->isValid()) {
                return null;
            }

            $normalized = [];
            TraceContextPropagator::getInstance()->inject($normalized, context: $context);
            $baggage = self::normalizedBaggage($carrier, $baggageAllowlist);
            if ($baggage === null) {
                return null;
            }
            $normalized = array_replace($normalized, $baggage);

            if (isset($normalized['baggage']) && strlen($normalized['baggage']) > self::LIMITS['baggage']) {
                return null;
            }
            $result = [
                'schema' => self::SCHEMA,
                'version' => 1,
                'carrier' => $normalized,
            ];

            if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) > 4096) {
                return null;
            }
            $diagnostic = $normalized === $carrier ? null : 'filtered';

            return $result;
        } catch (Throwable) {
            return null;
        }
    }

    private static function preparedAdmission(): ?array
    {
        $fiber = Fiber::getCurrent();
        if ($fiber !== null && ! isset(self::$admissionFibers[$fiber])) {
            return null;
        }
        try {
            $prepared = self::$admissionKey === null ? null : Context::getCurrent()->get(self::$admissionKey);

            return is_array($prepared) && array_key_exists('carrier', $prepared)
                && ($prepared['fiber'] ?? null) === $fiber ? $prepared : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, string> $carrier
     * @param list<string> $allowlist
     * @return array<string, string>|null
     */
    private static function normalizedBaggage(array $carrier, array $allowlist): ?array
    {
        if ($allowlist === [] || ! isset($carrier['baggage'])) {
            return [];
        }
        $context = BaggagePropagator::getInstance()->extract($carrier, context: Context::getRoot());
        $builder = Baggage::getBuilder();
        $count = 0;
        foreach (Baggage::fromContext($context)->getAll() as $key => $entry) {
            if (! in_array($key, $allowlist, true)) {
                continue;
            }
            if (++$count > 32) {
                return null;
            }
            $builder->set($key, $entry->getValue(), $entry->getMetadata());
        }
        $normalized = [];
        BaggagePropagator::getInstance()->inject(
            $normalized,
            context: $builder->build()
                ->storeInContext(Context::getRoot()),
        );

        return $normalized;
    }
}
