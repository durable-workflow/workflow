<?php

declare(strict_types=1);

namespace Tests\Unit\V2;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Workflow\Exceptions\NonRetryableException;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\InvocableActivityHandler;

final class InvocableActivityHandlerTest extends TestCase
{
    public function testReturnsSuccessResultEnvelope(): void
    {
        $handler = new InvocableActivityHandler(
            [
                'billing.charge-card' => static fn (int $amount, string $currency): array => [
                    'approved' => true,
                    'amount' => $amount,
                    'currency' => $currency,
                ],
            ],
            carrier: 'lambda-adapter',
            resultCodec: 'avro',
        );

        $output = $handler->handle($this->activityInput([4200, 'USD']));

        $this->assertSame('durable-workflow.v2.external-task-result', $output['schema']);
        $this->assertSame(1, $output['version']);
        $this->assertSame('succeeded', $output['outcome']['status']);
        $this->assertTrue($output['outcome']['recorded']);
        $this->assertSame('acttask_01HV7D3G3G61TAH2YB5RK45XJS', $output['task']['id']);
        $this->assertSame('activity_task', $output['task']['kind']);
        $this->assertSame('attempt_01HV7D3KJ1C8WQNNY8MVM8J40X', $output['task']['idempotency_key']);
        $this->assertSame('lambda-adapter', $output['metadata']['carrier']);
        $this->assertSame('billing.charge-card', $output['metadata']['handler']);
        $this->assertSame('avro', $output['result']['payload']['codec']);
        $this->assertSame(
            [
                'approved' => true,
                'amount' => 4200,
                'currency' => 'USD',
            ],
            Serializer::unserializeWithCodec('avro', $output['result']['payload']['blob']),
        );
    }

    public function testFailsClosedForExpiredLeaseWithoutInvokingHandler(): void
    {
        $invoked = false;
        $envelope = $this->activityInput();
        $envelope['lease']['expires_at'] = $this->iso(new DateTimeImmutable('-1 second', new DateTimeZone('UTC')));

        $output = (new InvocableActivityHandler([
            'billing.charge-card' => static function () use (&$invoked): array {
                $invoked = true;

                return [
                    'approved' => true,
                ];
            },
        ], resultCodec: 'avro'))->handle($envelope);

        $this->assertFalse($invoked);
        $this->assertSame('failed', $output['outcome']['status']);
        $this->assertTrue($output['outcome']['retryable']);
        $this->assertSame('timeout', $output['failure']['kind']);
        $this->assertSame('deadline_exceeded', $output['failure']['classification']);
        $this->assertSame('deadline_exceeded', $output['failure']['timeout_type']);
        $this->assertSame([
            'deadline' => 'lease.expires_at',
            'expires_at' => $envelope['lease']['expires_at'],
        ], $output['failure']['details']);
    }

    public function testRejectsSyncSuccessAfterDeadlineOverrun(): void
    {
        $envelope = $this->activityInput();
        $deadline = $this->isoFromTimestamp(microtime(true) + 0.10);
        $envelope['lease']['expires_at'] = $deadline;
        foreach (array_keys($envelope['deadlines']) as $key) {
            $envelope['deadlines'][$key] = $deadline;
        }

        $output = (new InvocableActivityHandler([
            'billing.charge-card' => static function (): array {
                usleep(150_000);

                return [
                    'approved' => true,
                ];
            },
        ], resultCodec: 'avro'))->handle($envelope);

        $this->assertSame('failed', $output['outcome']['status']);
        $this->assertTrue($output['outcome']['retryable']);
        $this->assertSame('deadline_exceeded', $output['failure']['classification']);
        $this->assertArrayNotHasKey('result', $output);
    }

    public function testFailsClosedForUnknownHandler(): void
    {
        $output = (new InvocableActivityHandler([], resultCodec: 'avro'))
            ->handle($this->activityInput(['x']));

        $this->assertSame('failed', $output['outcome']['status']);
        $this->assertFalse($output['outcome']['retryable']);
        $this->assertSame('application', $output['failure']['kind']);
        $this->assertSame('application_error', $output['failure']['classification']);
        $this->assertStringContainsString('no invocable activity handler registered', $output['failure']['message']);
    }

    public function testMapsNonRetryableExceptions(): void
    {
        $output = (new InvocableActivityHandler([
            'billing.charge-card' => static function (): void {
                throw new NonRetryableException('card rejected');
            },
        ], resultCodec: 'avro'))->handle($this->activityInput());

        $this->assertSame('failed', $output['outcome']['status']);
        $this->assertFalse($output['outcome']['retryable']);
        $this->assertSame('application', $output['failure']['kind']);
        $this->assertSame('card rejected', $output['failure']['message']);
    }

    public function testRejectsWorkflowTaskInputs(): void
    {
        $output = (new InvocableActivityHandler([
            'billing.invoice.workflow' => static fn (): array => [
                'ignored' => true,
            ],
        ], resultCodec: 'avro'))->handle($this->workflowInput());

        $this->assertSame('failed', $output['outcome']['status']);
        $this->assertFalse($output['outcome']['retryable']);
        $this->assertSame('workflow_task', $output['task']['kind']);
        $this->assertStringContainsString('only accept activity_task', $output['failure']['message']);
    }

    public function testMapsMalformedPayloadsToDecodeFailure(): void
    {
        $envelope = $this->activityInput();
        $envelope['payloads']['arguments'] = [
            'codec' => 'json',
            'blob' => 'not-a-valid-payload',
        ];

        $output = (new InvocableActivityHandler([
            'billing.charge-card' => static fn (): array => [
                'ignored' => true,
            ],
        ], resultCodec: 'avro'))->handle($envelope);

        $this->assertSame('failed', $output['outcome']['status']);
        $this->assertFalse($output['outcome']['retryable']);
        $this->assertSame('decode_failure', $output['failure']['kind']);
        $this->assertSame('decode_failure', $output['failure']['classification']);
        $this->assertSame([
            'codec' => 'json',
        ], $output['failure']['details']);
    }

    public function testValidatesResultCodec(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported invocable result codec');

        new InvocableActivityHandler([], resultCodec: 'protobuf');
    }

    /**
     * @param  list<mixed>  $arguments
     * @return array<string, mixed>
     */
    private function activityInput(array $arguments = [], ?DateInterval $expiresIn = null): array
    {
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add($expiresIn ?? new DateInterval('PT5M'));

        $expiresAtString = $this->iso($expiresAt);

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
                'expires_at' => $this->iso(new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC'))),
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

    private function iso(DateTimeImmutable $timestamp): string
    {
        return $timestamp
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    private function isoFromTimestamp(float $timestamp): string
    {
        $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $timestamp), new DateTimeZone('UTC'));
        if (! $date instanceof DateTimeImmutable) {
            $date = new DateTimeImmutable('@' . (string) (int) $timestamp);
        }

        return $this->iso($date);
    }
}
