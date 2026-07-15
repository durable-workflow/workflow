<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use Workflow\V2\Contracts\ServiceBoundaryPolicy;
use Workflow\V2\Enums\ServiceCallOperationMode;

/**
 * Reference implementation of the cross-namespace service-call boundary.
 *
 * Hosts can replace this binding with a backend-backed policy. The default
 * implementation deliberately keeps counters in process so package and server
 * tests exercise the contract without requiring Redis or a dedicated policy
 * service.
 */
final class DefaultServiceBoundaryPolicy implements ServiceBoundaryPolicy
{
    public const POLICY_NAME = 'workflow.default-service-boundary';

    /**
     * @var array<string, int>
     */
    private array $inFlight = [];

    /**
     * @var array<string, list<int>>
     */
    private array $rateWindow = [];

    /**
     * @param array<string, mixed> $rules
     */
    public function __construct(
        private array $rules = [],
    ) {
    }

    public function evaluate(ServiceBoundaryRequest $request): ServiceBoundaryDecision
    {
        $denial = $this->checkAuthorization($request);
        if ($denial !== null) {
            return $denial;
        }

        $denial = $this->checkRateLimit($request);
        if ($denial !== null) {
            return $denial;
        }

        $denial = $this->checkConcurrency($request);
        if ($denial !== null) {
            return $denial;
        }

        $denial = $this->checkCircuitBreak($request);
        if ($denial !== null) {
            return $denial;
        }

        $this->trackAdmission($request);

        return ServiceBoundaryDecision::allow(
            policyName: self::POLICY_NAME,
            metadata: [
                'boundary_key' => $request->boundaryKey(),
            ],
        );
    }

    public function release(ServiceBoundaryRequest $request): void
    {
        $key = $request->boundaryKey();

        if (! isset($this->inFlight[$key])) {
            return;
        }

        if (--$this->inFlight[$key] <= 0) {
            unset($this->inFlight[$key]);
        }
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function setRules(array $rules): void
    {
        $this->rules = $rules;
    }

    private function checkAuthorization(ServiceBoundaryRequest $request): ?ServiceBoundaryDecision
    {
        $globalAuth = $this->arrayAt($this->rules, ['authorization']);
        $denial = $this->checkRequiredRoles($request, $globalAuth, 'operation');
        if ($denial !== null) {
            return $denial;
        }

        foreach ([
            'endpoint' => $request->endpointBoundaryPolicy,
            'service' => $request->serviceBoundaryPolicy,
            'operation' => $request->operationBoundaryPolicy,
        ] as $axis => $policy) {
            $denial = $this->checkRequiredRoles($request, $policy, $axis);
            if ($denial !== null) {
                return $denial;
            }

            $denial = $this->checkCallerNamespacePolicy($request, $policy, $axis);
            if ($denial !== null) {
                return $denial;
            }
        }

        $namespaceRules = $this->arrayAt($this->rules, ['namespaces']);
        $denial = $this->checkCallerNamespacePolicy($request, $namespaceRules, 'operation');
        if ($denial !== null) {
            return $denial;
        }

        if (
            ($namespaceRules['cross_namespace_default'] ?? 'allow') === 'deny'
            && $request->callerNamespace !== null
            && $request->callerNamespace !== $request->targetNamespace
        ) {
            return ServiceBoundaryDecision::denyNamespacePolicy(
                reason: 'cross_namespace_blocked',
                message: sprintf(
                    'Cross-namespace calls from [%s] to [%s] are blocked by service-boundary policy.',
                    $request->callerNamespace,
                    $request->targetNamespace,
                ),
                policyName: self::POLICY_NAME,
                metadata: [
                    'forbidden_axis' => 'operation',
                ],
            );
        }

        if (($this->rules['default_action'] ?? 'allow') === 'deny') {
            return ServiceBoundaryDecision::denyAuthorization(
                reason: 'default_action_denied',
                message: 'Service-boundary policy default action is deny.',
                policyName: self::POLICY_NAME,
                metadata: [
                    'forbidden_axis' => 'operation',
                ],
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function checkRequiredRoles(
        ServiceBoundaryRequest $request,
        array $policy,
        string $axis,
    ): ?ServiceBoundaryDecision {
        $required = $this->firstList($policy, [['required_roles'], ['authorization', 'required_roles']]) ?? [];

        if ($required === []) {
            return null;
        }

        foreach ($required as $role) {
            if (in_array($role, $request->principal->roles, true)) {
                return null;
            }
        }

        return ServiceBoundaryDecision::denyAuthorization(
            reason: 'missing_required_role',
            message: sprintf(
                'Caller [%s] is missing one of the required roles for service-boundary admission.',
                $request->principal->subject,
            ),
            policyName: self::POLICY_NAME,
            metadata: [
                'forbidden_axis' => $axis,
            ],
        );
    }

    /**
     * @param array<string, mixed> $policy
     */
    private function checkCallerNamespacePolicy(
        ServiceBoundaryRequest $request,
        array $policy,
        string $axis,
    ): ?ServiceBoundaryDecision {
        $caller = $request->callerNamespace;

        $deny = $this->firstList($policy, [
            ['deny_callers'],
            ['caller_namespaces', 'deny'],
            ['caller_namespaces', 'deny_callers'],
            ['authorization', 'deny_callers'],
            ['authorization', 'caller_namespaces', 'deny'],
            ['authorization', 'caller_namespaces', 'deny_callers'],
        ]) ?? [];

        if ($caller !== null && in_array($caller, $deny, true)) {
            return ServiceBoundaryDecision::denyNamespacePolicy(
                reason: 'caller_namespace_denied',
                message: sprintf(
                    'Caller namespace [%s] is explicitly denied by service-boundary policy.',
                    $caller,
                ),
                policyName: self::POLICY_NAME,
                metadata: [
                    'forbidden_axis' => $axis,
                ],
            );
        }

        $allow = $this->firstList($policy, [
            ['allow_callers'],
            ['caller_namespaces', 'allow'],
            ['caller_namespaces', 'allow_callers'],
            ['authorization', 'allow_callers'],
            ['authorization', 'caller_namespaces', 'allow'],
            ['authorization', 'caller_namespaces', 'allow_callers'],
        ]);

        if ($allow === null) {
            return null;
        }

        if ($caller === null) {
            return ServiceBoundaryDecision::denyNamespacePolicy(
                reason: 'caller_namespace_missing',
                message: 'Caller namespace is required by service-boundary allow policy.',
                policyName: self::POLICY_NAME,
                metadata: [
                    'forbidden_axis' => $axis,
                ],
            );
        }

        if (! in_array($caller, $allow, true)) {
            return ServiceBoundaryDecision::denyNamespacePolicy(
                reason: 'caller_namespace_not_allowed',
                message: sprintf(
                    'Caller namespace [%s] is not on the service-boundary allow list for target namespace [%s].',
                    $caller,
                    $request->targetNamespace,
                ),
                policyName: self::POLICY_NAME,
                metadata: [
                    'forbidden_axis' => $axis,
                ],
            );
        }

        return null;
    }

    private function checkRateLimit(ServiceBoundaryRequest $request): ?ServiceBoundaryDecision
    {
        $rules = $this->effectiveRules($request, 'rate_limit');
        $perMinute = isset($rules['requests_per_minute']) ? (int) $rules['requests_per_minute'] : null;

        if ($perMinute === null || $perMinute <= 0) {
            return null;
        }

        if ($this->syncOnlySkips($rules, $request)) {
            return null;
        }

        $key = $request->boundaryKey();
        $now = time();
        $window = $this->rateWindow[$key] ?? [];
        $window = array_values(array_filter($window, static fn (int $ts): bool => $ts > $now - 60));

        if (count($window) >= $perMinute) {
            $this->rateWindow[$key] = $window;

            return ServiceBoundaryDecision::denyRateLimit(
                retryAfterSeconds: $this->retryAfterSeconds($rules),
                message: sprintf(
                    'Rate limit %d requests/minute reached for service-boundary [%s].',
                    $perMinute,
                    $key,
                ),
                policyName: self::POLICY_NAME,
                metadata: [
                    'observed_window_count' => count($window),
                    'requests_per_minute' => $perMinute,
                    'boundary_key' => $key,
                ],
            );
        }

        $this->rateWindow[$key] = $window;

        return null;
    }

    private function checkConcurrency(ServiceBoundaryRequest $request): ?ServiceBoundaryDecision
    {
        $rules = $this->effectiveRules($request, 'concurrency_limit', 'concurrency');
        $max = isset($rules['max_in_flight']) ? (int) $rules['max_in_flight'] : null;

        if ($max === null || $max <= 0) {
            return null;
        }

        if ($this->syncOnlySkips($rules, $request)) {
            return null;
        }

        $key = $request->boundaryKey();
        $current = $this->inFlight[$key] ?? 0;

        if ($current < $max) {
            return null;
        }

        return ServiceBoundaryDecision::denyConcurrency(
            retryAfterSeconds: $this->retryAfterSeconds($rules),
            message: sprintf('Concurrency limit %d reached for service-boundary [%s].', $max, $key),
            policyName: self::POLICY_NAME,
            metadata: [
                'observed_in_flight' => $current,
                'max_in_flight' => $max,
                'boundary_key' => $key,
            ],
        );
    }

    private function checkCircuitBreak(ServiceBoundaryRequest $request): ?ServiceBoundaryDecision
    {
        $rules = $this->effectiveRules($request, 'circuit_break');
        $openTargets = $this->listAt($rules, ['open_targets']) ?? [];

        $isOpen = ($rules['open'] ?? false) === true
            || in_array($request->targetKey(), $openTargets, true);

        if (! $isOpen) {
            return null;
        }

        return ServiceBoundaryDecision::denyCircuitOpen(
            retryAfterSeconds: $this->retryAfterSeconds($rules),
            message: sprintf('Service-boundary circuit is open for target [%s].', $request->targetKey()),
            policyName: self::POLICY_NAME,
            metadata: [
                'target_key' => $request->targetKey(),
            ],
        );
    }

    private function trackAdmission(ServiceBoundaryRequest $request): void
    {
        $concurrencyRules = $this->effectiveRules($request, 'concurrency_limit', 'concurrency');
        $max = isset($concurrencyRules['max_in_flight']) ? (int) $concurrencyRules['max_in_flight'] : null;

        if ($max !== null && $max > 0 && ! $this->syncOnlySkips($concurrencyRules, $request)) {
            $key = $request->boundaryKey();
            $this->inFlight[$key] = ($this->inFlight[$key] ?? 0) + 1;
        }

        $rateRules = $this->effectiveRules($request, 'rate_limit');
        $perMinute = isset($rateRules['requests_per_minute']) ? (int) $rateRules['requests_per_minute'] : null;

        if ($perMinute !== null && $perMinute > 0 && ! $this->syncOnlySkips($rateRules, $request)) {
            $this->rateWindow[$request->boundaryKey()][] = time();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveRules(
        ServiceBoundaryRequest $request,
        string $policyKey,
        ?string $globalKey = null,
    ): array {
        $global = $this->arrayAt($this->rules, [$globalKey ?? $policyKey]);
        $effectivePolicy = $request->effectiveBoundaryPolicy();
        $operation = $this->arrayAt($effectivePolicy, [$policyKey]);

        return ServiceBoundaryRequest::mergePolicy($global, $operation);
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function syncOnlySkips(array $rules, ServiceBoundaryRequest $request): bool
    {
        return ($rules['sync_only'] ?? false) === true
            && $request->operationMode !== ServiceCallOperationMode::Sync;
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function retryAfterSeconds(array $rules): ?int
    {
        return isset($rules['retry_after_seconds'])
            ? max(0, (int) $rules['retry_after_seconds'])
            : null;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<list<string>> $paths
     * @return list<string>|null
     */
    private function firstList(array $source, array $paths): ?array
    {
        foreach ($paths as $path) {
            $list = $this->listAt($source, $path);

            if ($list !== null) {
                return $list;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $path
     * @return list<string>|null
     */
    private function listAt(array $source, array $path): ?array
    {
        $value = $this->valueAt($source, $path);

        if (! is_array($value)) {
            return null;
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $entry): bool => is_string($entry) && trim($entry) !== '',
        ));
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $path
     * @return array<string, mixed>
     */
    private function arrayAt(array $source, array $path): array
    {
        $value = $this->valueAt($source, $path);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string> $path
     */
    private function valueAt(array $source, array $path): mixed
    {
        $value = $source;

        foreach ($path as $part) {
            if (! is_array($value) || ! array_key_exists($part, $value)) {
                return null;
            }

            $value = $value[$part];
        }

        return $value;
    }
}
