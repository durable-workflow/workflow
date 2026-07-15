<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Workflow\Exceptions\NonRetryableException;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\InvocableHttpAdapter;

final class InvocableHttpAdapterTest extends TestCase
{
    public function testReturnsHttpTwoHundredWithResultEnvelope(): void
    {
        $adapter = new InvocableHttpAdapter(
            [
                'billing.charge-card' => static fn (int $amount, string $currency): array => [
                    'approved' => true,
                    'amount' => $amount,
                    'currency' => $currency,
                ],
            ],
            carrier: 'php-invocable-http',
            resultCodec: 'avro',
        );

        $response = $adapter->handle(json_encode($this->activityInput([4200, 'USD'])));

        $this->assertSame(200, $response['status']);
        $this->assertSame(InvocableHttpAdapter::RESULT_MEDIA_TYPE, $response['headers']['Content-Type']);

        $body = json_decode($response['body'], associative: true);
        $this->assertIsArray($body);
        $this->assertSame('durable-workflow.v2.external-task-result', $body['schema']);
        $this->assertSame('succeeded', $body['outcome']['status']);
        $this->assertSame('php-invocable-http', $body['metadata']['carrier']);
        $this->assertSame('billing.charge-card', $body['metadata']['handler']);
        $this->assertSame(
            [
                'approved' => true,
                'amount' => 4200,
                'currency' => 'USD',
            ],
            Serializer::unserializeWithCodec('avro', $body['result']['payload']['blob']),
        );
    }

    public function testReturnsHttpFourHundredForInvalidJsonBody(): void
    {
        $adapter = new InvocableHttpAdapter([]);

        $response = $adapter->handle('{not valid json');

        $this->assertSame(400, $response['status']);
        $this->assertSame(InvocableHttpAdapter::ERROR_MEDIA_TYPE, $response['headers']['Content-Type']);

        $body = json_decode($response['body'], associative: true);
        $this->assertIsArray($body);
        $this->assertSame('invalid_invocable_request', $body['error']);
        $this->assertStringContainsString('not valid JSON', $body['message']);
    }

    public function testReturnsHttpFourHundredForJsonArrayBody(): void
    {
        $adapter = new InvocableHttpAdapter([]);

        $response = $adapter->handle('[]');

        $this->assertSame(400, $response['status']);
        $body = json_decode($response['body'], associative: true);
        $this->assertSame('invalid_invocable_request', $body['error']);
        $this->assertStringContainsString('JSON object', $body['message']);
    }

    public function testReturnsResultEnvelopeForActivityOnlyFailures(): void
    {
        $adapter = new InvocableHttpAdapter([
            'billing.charge-card' => static function (): void {
                throw new NonRetryableException('card declined');
            },
        ], resultCodec: 'avro');

        $response = $adapter->handle(json_encode($this->activityInput()));

        $this->assertSame(200, $response['status']);
        $body = json_decode($response['body'], associative: true);
        $this->assertSame('failed', $body['outcome']['status']);
        $this->assertFalse($body['outcome']['retryable']);
        $this->assertSame('card declined', $body['failure']['message']);
    }

    public function testRejectsWorkflowTaskInputWithStructuredFailureEnvelope(): void
    {
        $adapter = new InvocableHttpAdapter([
            'billing.invoice.workflow' => static fn (): array => [
                'ignored' => true,
            ],
        ], resultCodec: 'avro');

        $response = $adapter->handle(json_encode($this->workflowInput()));

        $this->assertSame(200, $response['status']);
        $body = json_decode($response['body'], associative: true);
        $this->assertSame('failed', $body['outcome']['status']);
        $this->assertFalse($body['outcome']['retryable']);
        $this->assertStringContainsString('only accept activity_task', $body['failure']['message']);
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array<string, mixed>
     */
    private function activityInput(array $arguments = [], ?DateInterval $expiresIn = null): array
    {
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add($expiresIn ?? new DateInterval('PT5M'));

        $expiresAtString = $expiresAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');

        return [
            'schema' => 'durable-workflow.v2.external-task-input',
            'version' => 1,
            'task' => [
                'id' => 'acttask_01HV7D3G3G61TAH2YB5RK45XJS',
                'kind' => 'activity_task',
                'attempt' => 1,
                'activity_attempt_id' => 'attempt_01HV7D3KJ1C8WQNNY8MVM8J40X',
                'task_queue' => 'billing-activities',
                'handler' => 'billing.charge-card',
                'connection' => null,
                'idempotency_key' => 'attempt_01HV7D3KJ1C8WQNNY8MVM8J40X',
            ],
            'workflow' => [
                'id' => 'invoice-2026-0001',
                'run_id' => 'run_01HV7D2M5N1HDZ4Z8XBQJY6P9R',
            ],
            'lease' => [
                'owner' => 'worker-php-a',
                'expires_at' => $expiresAtString,
                'heartbeat_endpoint' => '/api/worker/activity-tasks/acttask_01HV7D3G3G61TAH2YB5RK45XJS/heartbeat',
            ],
            'payloads' => [
                'arguments' => [
                    'codec' => 'avro',
                    'blob' => Serializer::serializeWithCodec('avro', $arguments),
                ],
            ],
            'deadlines' => [
                'schedule_to_start' => $expiresAtString,
                'start_to_close' => $expiresAtString,
                'schedule_to_close' => $expiresAtString,
                'heartbeat' => $expiresAtString,
            ],
            'headers' => [
                'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-7a085853722dc6d2-00',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workflowInput(): array
    {
        return [
            'schema' => 'durable-workflow.v2.external-task-input',
            'version' => 1,
            'task' => [
                'id' => 'wft_01HV7D2K3X9K2M7YVQ4T0A1B2C',
                'kind' => 'workflow_task',
                'attempt' => 2,
                'task_queue' => 'billing-workflows',
                'handler' => 'billing.invoice.workflow',
                'connection' => null,
                'compatibility' => 'build-2026-04-22',
                'idempotency_key' => 'wft_01HV7D2K3X9K2M7YVQ4T0A1B2C:2',
            ],
            'workflow' => [
                'id' => 'invoice-2026-0001',
                'run_id' => 'run_01HV7D2M5N1HDZ4Z8XBQJY6P9R',
                'status' => 'running',
                'resume' => [
                    'workflow_wait_kind' => 'activity',
                ],
            ],
            'lease' => [
                'owner' => 'worker-php-a',
                'expires_at' => (new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC')))->format(
                    'Y-m-d\TH:i:s.u\Z'
                ),
                'heartbeat_endpoint' => '/api/worker/workflow-tasks/wft_01HV7D2K3X9K2M7YVQ4T0A1B2C/heartbeat',
            ],
            'payloads' => [
                'arguments' => [
                    'codec' => 'avro',
                    'blob' => Serializer::serializeWithCodec('avro', []),
                ],
            ],
            'history' => [
                'events' => [
                    [
                        'event_id' => 'evt_01HV7D2M7A72M5JHVR75MB4BF3',
                        'event_type' => 'WorkflowStarted',
                        'sequence' => 1,
                    ],
                ],
                'last_sequence' => 42,
                'next_page_token' => null,
                'encoding' => null,
            ],
            'headers' => [
                'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00',
            ],
        ];
    }
}
