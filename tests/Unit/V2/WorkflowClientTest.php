<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;
use Workflow\Serializers\Serializer;
use Workflow\V2\Client\WorkflowClient;
use Workflow\V2\Client\WorkflowClientException;
use Workflow\V2\Support\WorkerProtocolVersion;

final class WorkflowClientTest extends TestCase
{
    public function testWithNamespaceClonesConnectionSettingsAndUsesSelectedNamespaceHeader(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'url' => $request->url(),
                'authorization' => $request->hasHeader('Authorization', 'Bearer test-token'),
                'default' => $request->hasHeader('X-Namespace', 'default'),
                'tenant_b' => $request->hasHeader('X-Namespace', 'tenant-b'),
            ];

            return $http->response([
                'started' => true,
                'workflow_id' => 'counter-php',
            ]);
        });

        $defaultClient = new WorkflowClient($http, 'http://server:8080/', 'test-token');
        $tenantBClient = $defaultClient->withNamespace('tenant-b');

        $this->assertSame('default', $defaultClient->namespace());
        $this->assertSame('tenant-b', $tenantBClient->namespace());

        $tenantBClient->startWorkflow('conformance.php.Counter', 'counter-php');

        $this->assertSame('http://server:8080/api/workflows', $requests[0]['url']);
        $this->assertTrue($requests[0]['authorization']);
        $this->assertFalse($requests[0]['default']);
        $this->assertTrue($requests[0]['tenant_b']);
    }

    public function testSignalWorkflowUsesControlPlaneEndpointAndPayloadEnvelope(): void
    {
        $http = new HttpFactory();
        $captured = null;

        $http->fake(function (Request $request) use ($http, &$captured) {
            $captured = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
                'authorization' => $request->hasHeader('Authorization', 'Bearer test-token'),
                'namespace' => $request->hasHeader('X-Namespace', 'default'),
                'control_plane' => $request->hasHeader(
                    WorkflowClient::CONTROL_PLANE_HEADER,
                    WorkflowClient::CONTROL_PLANE_VERSION,
                ),
                'worker_protocol' => $request->hasHeader(
                    WorkflowClient::WORKER_PROTOCOL_HEADER,
                    WorkerProtocolVersion::VERSION,
                ),
            ];

            return $http->response([
                'accepted' => true,
                'workflow_id' => 'counter-php',
                'command_status' => 'accepted',
            ]);
        });

        $client = new WorkflowClient($http, 'http://server:8080/', 'test-token');
        $response = $client->signalWorkflow('counter-php', 'increment', [3]);

        $this->assertSame([
            'accepted' => true,
            'workflow_id' => 'counter-php',
            'command_status' => 'accepted',
        ], $response);
        $this->assertSame('POST', $captured['method']);
        $this->assertSame('http://server:8080/api/workflows/counter-php/signal/increment', $captured['url']);
        $this->assertSame('avro', $captured['body']['input']['codec'] ?? null);
        $this->assertSame([3], Serializer::unserializeWithCodec('avro', $captured['body']['input']['blob']));
        $this->assertTrue($captured['authorization']);
        $this->assertTrue($captured['namespace']);
        $this->assertTrue($captured['control_plane']);
        $this->assertTrue($captured['worker_protocol']);
    }

    public function testQueryWorkflowDecodesResultEnvelope(): void
    {
        $http = new HttpFactory();
        $requests = [];

        $http->fake(function (Request $request) use ($http, &$requests) {
            $requests[] = [
                'method' => strtoupper($request->method()),
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            return $http->response([
                'success' => true,
                'result' => 8,
                'result_envelope' => [
                    'codec' => 'avro',
                    'blob' => Serializer::serializeWithCodec('avro', 8),
                ],
            ]);
        });

        $client = new WorkflowClient($http, 'http://server:8080', 'test-token');

        $this->assertSame(8, $client->queryWorkflow('counter-python', 'current', []));
        $this->assertSame([
            [
                'method' => 'POST',
                'url' => 'http://server:8080/api/workflows/counter-python/query/current',
                'body' => [],
            ],
        ], $requests);
    }

    public function testStartWorkflowSendsTypeIdOptionsAndInputEnvelope(): void
    {
        $http = new HttpFactory();
        $body = null;

        $http->fake(function (Request $request) use ($http, &$body) {
            $body = $request->data();

            return $http->response([
                'started' => true,
                'workflow_id' => 'counter-php',
                'workflow_run_id' => 'run-1',
            ]);
        });

        $client = new WorkflowClient($http, 'http://server:8080', 'test-token');
        $response = $client->startWorkflow(
            'conformance.php.Counter',
            'counter-php',
            [0],
            ['task_queue' => 'signals-queries-php'],
        );

        $this->assertSame('counter-php', $response['workflow_id']);
        $this->assertSame('conformance.php.Counter', $body['workflow_type']);
        $this->assertSame('counter-php', $body['workflow_id']);
        $this->assertSame('signals-queries-php', $body['task_queue']);
        $this->assertSame([0], Serializer::unserializeWithCodec('avro', $body['input']['blob']));
    }

    public function testRejectedSignalKeepsExactResponseBody(): void
    {
        $http = new HttpFactory();

        $http->fake(fn () => $http->response([
            'accepted' => false,
            'reason' => 'unknown_signal',
            'message' => 'Workflow signal [increment] is unknown.',
        ], 404));

        $client = new WorkflowClient($http, 'http://server:8080', 'test-token');

        $this->expectException(WorkflowClientException::class);

        try {
            $client->signalWorkflow('counter-php', 'increment', [1]);
        } catch (WorkflowClientException $exception) {
            $this->assertSame(404, $exception->statusCode());
            $this->assertSame([
                'accepted' => false,
                'reason' => 'unknown_signal',
                'message' => 'Workflow signal [increment] is unknown.',
            ], $exception->body());

            throw $exception;
        }
    }
}
