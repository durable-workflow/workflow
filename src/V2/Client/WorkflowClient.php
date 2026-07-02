<?php

declare(strict_types=1);

namespace Workflow\V2\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;
use RuntimeException;
use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\WorkerProtocolVersion;

/**
 * Control-plane HTTP client for Durable Workflow's standalone server.
 *
 * This covers the public workflow operations conformance uses across SDKs:
 * start, signal, query, and update. It deliberately returns decoded server
 * payloads so callers can keep exact request/response evidence when an
 * operation is rejected.
 *
 * @api Stable v2 control-plane client API.
 */
final class WorkflowClient
{
    public const CONTROL_PLANE_VERSION = '2';

    public const CONTROL_PLANE_HEADER = 'X-Durable-Workflow-Control-Plane-Version';

    public const WORKER_PROTOCOL_HEADER = 'X-Durable-Workflow-Protocol-Version';

    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpFactory $http,
        string $baseUrl,
        private readonly ?string $token = null,
        private readonly string $namespace = 'default',
        private readonly int $defaultRequestTimeoutSeconds = 30,
        private readonly string $controlPlaneVersion = self::CONTROL_PLANE_VERSION,
        private readonly string $workerProtocolVersion = WorkerProtocolVersion::VERSION,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');

        if ($this->baseUrl === '') {
            throw new InvalidArgumentException('Base URL cannot be empty.');
        }

        if (trim($this->namespace) === '') {
            throw new InvalidArgumentException('Namespace cannot be empty.');
        }

        if ($this->defaultRequestTimeoutSeconds < 1) {
            throw new InvalidArgumentException('Default request timeout must be at least 1 second.');
        }
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function withNamespace(string $namespace): self
    {
        return new self(
            $this->http,
            $this->baseUrl,
            $this->token,
            $namespace,
            $this->defaultRequestTimeoutSeconds,
            $this->controlPlaneVersion,
            $this->workerProtocolVersion,
        );
    }

    /**
     * @param array<int, mixed> $arguments
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function startWorkflow(
        string $workflowType,
        ?string $workflowId = null,
        array $arguments = [],
        array $options = [],
        ?string $payloadCodec = null,
    ): array {
        if ($workflowType === '') {
            throw new InvalidArgumentException('Workflow type cannot be empty.');
        }

        $body = [
            'workflow_type' => $workflowType,
        ];

        if ($workflowId !== null && $workflowId !== '') {
            $body['workflow_id'] = $workflowId;
        }

        if ($arguments !== []) {
            $body['input'] = $this->payloadEnvelope($arguments, $payloadCodec);
        }

        foreach ($options as $key => $value) {
            if (is_string($key) && $key !== '' && $value !== null) {
                $body[$key] = $value;
            }
        }

        return $this->post('/workflows', $body);
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array<string, mixed>
     */
    public function signalWorkflow(
        string $workflowId,
        string $signalName,
        array $arguments = [],
        ?string $payloadCodec = null,
    ): array {
        if ($workflowId === '') {
            throw new InvalidArgumentException('Workflow id cannot be empty.');
        }

        if ($signalName === '') {
            throw new InvalidArgumentException('Signal name cannot be empty.');
        }

        return $this->post(
            sprintf('/workflows/%s/signal/%s', $this->pathSegment($workflowId), $this->pathSegment($signalName)),
            $this->bodyWithInput($arguments, $payloadCodec),
        );
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array<string, mixed>
     */
    public function updateWorkflow(
        string $workflowId,
        string $updateName,
        array $arguments = [],
        ?string $waitFor = null,
        ?int $waitTimeoutSeconds = null,
        ?string $requestId = null,
        ?string $payloadCodec = null,
    ): array {
        if ($workflowId === '') {
            throw new InvalidArgumentException('Workflow id cannot be empty.');
        }

        if ($updateName === '') {
            throw new InvalidArgumentException('Update name cannot be empty.');
        }

        $body = $this->bodyWithInput($arguments, $payloadCodec);

        if ($waitFor !== null) {
            $body['wait_for'] = $waitFor;
        }

        if ($waitTimeoutSeconds !== null) {
            $body['wait_timeout_seconds'] = $waitTimeoutSeconds;
        }

        if ($requestId !== null && $requestId !== '') {
            $body['request_id'] = $requestId;
        }

        return $this->post(
            sprintf('/workflows/%s/update/%s', $this->pathSegment($workflowId), $this->pathSegment($updateName)),
            $body,
        );
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array<string, mixed>
     */
    public function updateWorkflowRun(
        string $workflowId,
        string $runId,
        string $updateName,
        array $arguments = [],
        ?string $waitFor = null,
        ?int $waitTimeoutSeconds = null,
        ?string $requestId = null,
        ?string $payloadCodec = null,
    ): array {
        if ($workflowId === '') {
            throw new InvalidArgumentException('Workflow id cannot be empty.');
        }

        if ($runId === '') {
            throw new InvalidArgumentException('Run id cannot be empty.');
        }

        if ($updateName === '') {
            throw new InvalidArgumentException('Update name cannot be empty.');
        }

        $body = $this->bodyWithInput($arguments, $payloadCodec);

        if ($waitFor !== null) {
            $body['wait_for'] = $waitFor;
        }

        if ($waitTimeoutSeconds !== null) {
            $body['wait_timeout_seconds'] = $waitTimeoutSeconds;
        }

        if ($requestId !== null && $requestId !== '') {
            $body['request_id'] = $requestId;
        }

        return $this->post(
            sprintf(
                '/workflows/%s/runs/%s/update/%s',
                $this->pathSegment($workflowId),
                $this->pathSegment($runId),
                $this->pathSegment($updateName),
            ),
            $body,
        );
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function queryWorkflow(
        string $workflowId,
        string $queryName,
        array $arguments = [],
        ?string $payloadCodec = null,
    ): mixed {
        if ($workflowId === '') {
            throw new InvalidArgumentException('Workflow id cannot be empty.');
        }

        if ($queryName === '') {
            throw new InvalidArgumentException('Query name cannot be empty.');
        }

        $response = $this->post(
            sprintf('/workflows/%s/query/%s', $this->pathSegment($workflowId), $this->pathSegment($queryName)),
            $this->bodyWithInput($arguments, $payloadCodec),
        );

        return $this->decodeResult($response);
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array<string, mixed>
     */
    private function bodyWithInput(array $arguments, ?string $payloadCodec): array
    {
        if ($arguments === []) {
            return [];
        }

        return [
            'input' => $this->payloadEnvelope($arguments, $payloadCodec),
        ];
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array{codec: string, blob: string}
     */
    private function payloadEnvelope(array $arguments, ?string $payloadCodec): array
    {
        $codec = CodecRegistry::canonicalize($payloadCodec);

        return [
            'codec' => $codec,
            'blob' => Serializer::serializeWithCodec($codec, array_values($arguments)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function decodeResult(array $payload): mixed
    {
        $envelope = $payload['result_envelope'] ?? null;

        if (is_array($envelope)) {
            $blob = $envelope['blob'] ?? null;
            $codec = $envelope['codec'] ?? null;

            if (is_string($blob)) {
                return Serializer::unserializeWithCodec(is_string($codec) ? $codec : null, $blob);
            }
        }

        return $payload['result'] ?? null;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body = []): array
    {
        try {
            $response = $this->http
                ->withHeaders($this->headers())
                ->timeout($this->defaultRequestTimeoutSeconds)
                ->post($this->baseUrl.'/api'.$path, $body);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(sprintf('Server unreachable: %s', $exception->getMessage()), 0, $exception);
        }

        return $this->decode($response, $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response, string $path): array
    {
        $json = $response->json();
        $body = is_array($json) ? $json : [];

        if (! $response->successful()) {
            $message = is_string($body['message'] ?? null)
                ? $body['message']
                : sprintf('Server returned HTTP %d for %s.', $response->status(), $path);

            throw new WorkflowClientException($message, $response->status(), $body);
        }

        return $body;
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
            self::WORKER_PROTOCOL_HEADER => $this->workerProtocolVersion,
        ];

        if ($this->token !== null && $this->token !== '') {
            $headers['Authorization'] = 'Bearer '.$this->token;
        }

        return $headers;
    }

    private function pathSegment(string $value): string
    {
        return rawurlencode($value);
    }
}
