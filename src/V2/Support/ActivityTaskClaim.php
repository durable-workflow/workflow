<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use DateTimeImmutable;
use DateTimeZone;
use Workflow\V2\Models\ActivityAttempt;
use Workflow\V2\Models\ActivityExecution;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

final class ActivityTaskClaim
{
    private readonly int $claimedAt;

    /**
     * @var array<string, mixed>
     */
    private readonly array $identity;

    private int $leaseAdmissionDeadline;

    private readonly ?int $businessAdmissionDeadline;

    private ?int $heartbeatAdmissionDeadline;

    private readonly ?int $heartbeatTimeout;

    private ?int $knownHeartbeatTime;

    private ?int $knownHeartbeatDeadline;

    private ?int $lastRenewalServerTime = null;

    private ?int $knownLeaseExpiry = null;

    private ?int $lastRenewalRequestStartedAt = null;

    public function __construct(
        public readonly WorkflowTask $task,
        public readonly WorkflowRun $run,
        public readonly ActivityExecution $execution,
        public readonly ActivityAttempt $attempt,
    ) {
        $monotonic = hrtime(true);
        $this->claimedAt = $monotonic;
        $this->identity = [
            'workflow_task_id' => $task->getKey(),
            'workflow_run_id' => $run->getKey(),
            'activity_execution_id' => $execution->getKey(),
            'activity_attempt_id' => $attempt->getKey(),
            'attempt_number' => $this->attemptNumber(),
            'lease_owner' => $task->lease_owner,
        ];
        $now = now();
        $leaseRemaining = $task->lease_expires_at === null
            ? 0
            : max(0, $now->diffInMicroseconds($task->lease_expires_at, false));
        $this->leaseAdmissionDeadline = $monotonic + (int) ($leaseRemaining * 1_000);
        $this->knownLeaseExpiry = self::utcMicroseconds($task->lease_expires_at?->toJSON());
        $policy = is_array($execution->retry_policy) ? $execution->retry_policy : [];
        $this->heartbeatTimeout = is_int($policy['heartbeat_timeout'] ?? null) && $policy['heartbeat_timeout'] > 0
            ? $policy['heartbeat_timeout'] : null;
        $this->knownHeartbeatTime = self::utcMicroseconds($execution->last_heartbeat_at?->toJSON());
        $this->knownHeartbeatDeadline = self::utcMicroseconds($execution->heartbeat_deadline_at?->toJSON());
        $this->heartbeatAdmissionDeadline = $execution->heartbeat_deadline_at === null
            ? null : $monotonic + (int) (max(
                0,
                $now->diffInMicroseconds($execution->heartbeat_deadline_at, false)
            ) * 1_000);
        $businessRemaining = null;
        foreach ([
            $execution->close_deadline_at,
            $execution->schedule_to_close_deadline_at,
            $run->run_deadline_at,
            $run->execution_deadline_at,
        ] as $deadline) {
            if ($deadline !== null) {
                $remaining = max(0, $now->diffInMicroseconds($deadline, false));
                $businessRemaining = $businessRemaining === null ? $remaining : min($businessRemaining, $remaining);
            }
        }
        $this->businessAdmissionDeadline = $businessRemaining === null
            ? null : $monotonic + (int) ($businessRemaining * 1_000);
    }

    /**
     * Retry admission within the captured claim; fresh stored authority still decides publication.
     */
    public function outcomeAdmissionDeadline(): int
    {
        $deadline = $this->businessAdmissionDeadline === null
            ? $this->leaseAdmissionDeadline
            : min($this->leaseAdmissionDeadline, $this->businessAdmissionDeadline);

        return $this->heartbeatAdmissionDeadline === null
            ? $deadline : min($deadline, $this->heartbeatAdmissionDeadline);
    }

    /**
     * A successful transport response may extend only its original live claim.
     * Capture the request clock before renewal begins so its latency consumes budget.
     *
     * @param array<string, mixed> $reply
     */
    public function acknowledgeLeaseRenewal(array $reply, int $requestStartedAt): bool
    {
        $receivedAt = hrtime(true);
        if ($requestStartedAt < $this->claimedAt || $requestStartedAt > $receivedAt
            || $receivedAt >= $this->outcomeAdmissionDeadline()
            || ! $this->matchesRenewal($reply)) {
            return false;
        }

        $window = self::renewalWindow($reply);
        if ($window === null || $requestStartedAt + $window['remaining'] * 1_000 <= $receivedAt
            || ($this->knownLeaseExpiry !== null && $window['expires'] < $this->knownLeaseExpiry)) {
            return false;
        }

        if ($this->lastRenewalServerTime !== null && $window['server'] <= $this->lastRenewalServerTime) {
            return $window['server'] === $this->lastRenewalServerTime
                && $window['expires'] === $this->knownLeaseExpiry
                && $requestStartedAt === $this->lastRenewalRequestStartedAt;
        }

        $this->leaseAdmissionDeadline = max(
            $this->leaseAdmissionDeadline,
            $requestStartedAt + $window['remaining'] * 1_000,
        );
        $this->acknowledgeHeartbeat($reply, $window['server'], $requestStartedAt, $receivedAt);
        $this->lastRenewalServerTime = $window['server'];
        $this->knownLeaseExpiry = $window['expires'];
        $this->lastRenewalRequestStartedAt = $requestStartedAt;

        return true;
    }

    public function activityExecutionId(): string
    {
        return $this->execution->id;
    }

    public function attemptId(): string
    {
        return $this->attempt->id;
    }

    public function attemptNumber(): int
    {
        return max(1, (int) $this->attempt->attempt_number);
    }

    /**
     * Observe committed application progress; runtime renewal never creates it.
     *
     * @param array<string, mixed> $reply
     */
    private function acknowledgeHeartbeat(array $reply, int $serverTime, int $requestStartedAt, int $receivedAt): void
    {
        if ($this->heartbeatAdmissionDeadline === null || $this->heartbeatTimeout === null) {
            return;
        }

        $heartbeat = self::utcMicroseconds($reply['last_heartbeat_at'] ?? null);
        $deadline = self::utcMicroseconds($reply['heartbeat_deadline_at'] ?? null);
        if ($heartbeat === null || $deadline === null || $heartbeat > $serverTime || $deadline <= $serverTime
            || ($this->knownHeartbeatTime !== null && $heartbeat <= $this->knownHeartbeatTime)
            || ($this->knownHeartbeatDeadline !== null && $deadline <= $this->knownHeartbeatDeadline)) {
            return;
        }

        $duration = $deadline - $heartbeat;
        $localDeadline = $requestStartedAt + ($deadline - $serverTime) * 1_000;
        if ($duration % 1_000_000 !== 0 || intdiv($duration, 1_000_000) !== $this->heartbeatTimeout
            || $localDeadline <= $receivedAt) {
            return;
        }

        $this->heartbeatAdmissionDeadline = max($this->heartbeatAdmissionDeadline, $localDeadline);
        $this->knownHeartbeatTime = $heartbeat;
        $this->knownHeartbeatDeadline = $deadline;
    }

    /**
     * @param array<string, mixed> $reply
     */
    private function matchesRenewal(array $reply): bool
    {
        if (($reply['lease_renewed'] ?? null) !== true || ($reply['can_continue'] ?? null) !== true
            || ($reply['cancel_requested'] ?? null) !== false) {
            return false;
        }

        foreach ($this->identity as $key => $value) {
            if ($value === null || $value === '' || ($reply[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $reply
     * @return array{server: int, expires: int, remaining: int}|null
     */
    private static function renewalWindow(array $reply): ?array
    {
        $server = self::utcMicroseconds($reply['server_time'] ?? null);
        $expires = self::utcMicroseconds($reply['lease_expires_at'] ?? null);
        if ($server === null || $expires === null || $expires <= $server || $expires - $server > 86_400_000_000) {
            return null;
        }

        return [
            'server' => $server,
            'expires' => $expires,
            'remaining' => $expires - $server,
        ];
    }

    private static function utcMicroseconds(mixed $value): ?int
    {
        if (! is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z\z/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            return null;
        }

        return (int) $date->format('U') * 1_000_000 + (int) $date->format('u');
    }
}
