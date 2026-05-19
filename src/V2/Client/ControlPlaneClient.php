<?php

declare(strict_types=1);

namespace Workflow\V2\Client;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use RuntimeException;
use Workflow\V2\Exceptions\ControlPlaneRequestException;

/**
 * HTTP client for Durable Workflow control-plane operations.
 *
 * The worker protocol client covers PHP worker processes. This client covers
 * PHP-facing operator/application calls to the standalone server so published
 * PHP artifacts can participate in cross-language signal/query conformance
 * without shelling out to the CLI.
 *
 * @api Stable v2 control-plane client API.
 */
final class ControlPlaneClient
{
    public const CONTROL_PLANE_VERSION = '2';

    public const CONTROL_PLANE_HEADER = 'X-Durable-Workflow-Control-Plane-Version';

    private readonly string $baseUrl;

    private readonly string $apiPath;

    private readonly string $controlPlaneVersion;

    public function __construct(
        private readonly HttpFactory $http,
        string $baseUrl,
        private readonly ?string $token = null,
        private readonly string $namespace = 'default',
        ?string $controlPlaneVersion = null,
        private readonly int $defaultRequestTimeoutSeconds = 30,
        string $apiPath = '/api',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiPath = self::normalizePath($apiPath);
        $this->controlPlaneVersion = $controlPlaneVersion ?? self::CONTROL_PLANE_VERSION;

        if ($this->baseUrl === '') {
            throw new InvalidArgumentException('Base URL must not be empty.');
        }

        if ($this->defaultRequestTimeoutSeconds < 1) {
            throw new InvalidArgumentException('Default request timeout must be at least 1 second.');
        }
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function startWorkflow(
        string $workflowType,
        ?string $workflowId = null,
        array $arguments = [],
        array $options = [],
    ): array {
        $body = $this->withoutNulls([
            'workflow_id' => $workflowId,
            'workflow_type' => $workflowType,
            'task_queue' => $this->stringOption($options, 'task_queue'),
            'input' => $arguments === [] ? null : $arguments,
            'business_key' => $this->stringOption($options, 'business_key'),
            'memo' => $this->arrayOption($options, 'memo'),
            'search_attributes' => $this->arrayOption($options, 'search_attributes'),
            'duplicate_policy' => $this->stringOption($options, 'duplicate_policy'),
            'execution_timeout_seconds' => $this->intOption($options, 'execution_timeout_seconds'),
            'run_timeout_seconds' => $this->intOption($options, 'run_timeout_seconds'),
            'priority' => $this->intOption($options, 'priority'),
            'fairness_key' => $this->stringOption($options, 'fairness_key'),
            'fairness_weight' => $this->intOption($options, 'fairness_weight'),
        ]);

        return $this->post('/workflows', $body, [200, 201, 202]);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function signalWorkflow(
        string $workflowId,
        string $signalName,
        array $arguments = [],
        array $options = [],
    ): array {
        $runId = $this->stringOption($options, 'run_id');
        $path = $runId !== null
            ? sprintf(
                '/workflows/%s/runs/%s/signal/%s',
                $this->pathSegment($workflowId),
                $this->pathSegment($runId),
                $this->pathSegment($signalName),
            )
            : sprintf(
                '/workflows/%s/signal/%s',
                $this->pathSegment($workflowId),
                $this->pathSegment($signalName),
            );

        $body = $this->withoutNulls([
            'input' => $arguments === [] ? null : $arguments,
            'request_id' => $this->stringOption($options, 'request_id'),
        ]);

        return $this->post($path, $body, [200, 202]);
    }

    /**
     * @param array<int|string, mixed> $arguments
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function queryWorkflow(
        string $workflowId,
        string $queryName,
        array $arguments = [],
        array $options = [],
    ): array {
        $runId = $this->stringOption($options, 'run_id');
        $path = $runId !== null
            ? sprintf(
                '/workflows/%s/runs/%s/query/%s',
                $this->pathSegment($workflowId),
                $this->pathSegment($runId),
                $this->pathSegment($queryName),
            )
            : sprintf(
                '/workflows/%s/query/%s',
                $this->pathSegment($workflowId),
                $this->pathSegment($queryName),
            );

        $body = $arguments === [] ? [] : ['input' => $arguments];

        return $this->post($path, $body, [200]);
    }

    /**
     * @return array<string, mixed>
     */
    public function describeWorkflow(string $workflowId): array
    {
        return $this->get(sprintf('/workflows/%s', $this->pathSegment($workflowId)));
    }

    /**
     * @return array<string, mixed>
     */
    public function describeWorkflowRun(string $workflowId, string $runId): array
    {
        return $this->get(sprintf(
            '/workflows/%s/runs/%s',
            $this->pathSegment($workflowId),
            $this->pathSegment($runId),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function clusterInfo(): array
    {
        return $this->get('/cluster/info', enforceControlPlaneHeader: false);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(
        string $path,
        ?int $requestTimeoutSeconds = null,
        bool $enforceControlPlaneHeader = true,
    ): array {
        $response = $this->http
            ->withHeaders($this->headers())
            ->timeout($requestTimeoutSeconds ?? $this->defaultRequestTimeoutSeconds)
            ->get($this->url($path));

        return $this->decode($response, $path, $enforceControlPlaneHeader);
    }

    /**
     * @param array<string, mixed> $body
     * @param list<int> $successStatuses
     * @return array<string, mixed>
     */
    private function post(
        string $path,
        array $body,
        array $successStatuses,
        ?int $requestTimeoutSeconds = null,
    ): array {
        $response = $this->http
            ->withHeaders($this->headers())
            ->timeout($requestTimeoutSeconds ?? $this->defaultRequestTimeoutSeconds)
            ->post($this->url($path), $body);

        return $this->decode($response, $path, true, $successStatuses);
    }

    /**
     * @param list<int> $successStatuses
     * @return array<string, mixed>
     */
    private function decode(
        Response $response,
        string $path,
        bool $enforceControlPlaneHeader,
        array $successStatuses = [200],
    ): array {
        $json = $response->json();
        $body = is_array($json) ? $json : [];
        $status = $response->status();

        if (! in_array($status, $successStatuses, true)) {
            $message = $this->errorMessage($path, $status, $body);

            throw new ControlPlaneRequestException($message, $status, $body === [] ? null : $body);
        }

        if ($enforceControlPlaneHeader) {
            $version = $response->header(self::CONTROL_PLANE_HEADER);

            if (! is_string($version) || trim($version) !== $this->controlPlaneVersion) {
                throw new RuntimeException(sprintf(
                    'Durable Workflow server response for [%s] used control-plane version [%s]; expected [%s].',
                    $path,
                    is_string($version) && $version !== '' ? $version : 'missing',
                    $this->controlPlaneVersion,
                ));
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function errorMessage(string $path, int $status, array $body): string
    {
        foreach (['message', 'error'] as $field) {
            $value = $body[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $reason = $body['reason'] ?? null;
        if (is_string($reason) && $reason !== '') {
            return sprintf('Durable Workflow request to [%s] failed with [%s] (HTTP %d).', $path, $reason, $status);
        }

        return sprintf('Durable Workflow request to [%s] failed with HTTP %d.', $path, $status);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Namespace' => $this->namespace,
            self::CONTROL_PLANE_HEADER => $this->controlPlaneVersion,
        ];

        if ($this->token !== null && $this->token !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        return $headers;
    }

    private function url(string $path): string
    {
        return $this->baseUrl . $this->apiPath . self::normalizePath($path);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function intOption(array $options, string $key): ?int
    {
        $value = $options[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function arrayOption(array $options, string $key): ?array
    {
        $value = $options[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function withoutNulls(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null);
    }

    private function pathSegment(string $value): string
    {
        return rawurlencode($value);
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);

        return $path === '' || $path === '/'
            ? ''
            : '/'.trim($path, '/');
    }
}
