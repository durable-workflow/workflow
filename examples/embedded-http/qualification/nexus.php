<?php

declare(strict_types=1);

use App\Workflows\PublishedNexusCaller;
use App\Workflows\PublishedNexusFailure;
use App\Workflows\PublishedNexusTarget;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/nexus-workflows.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

final class PublishedEmbeddedNexusProbe
{
    private array $processes = [];

    public function run(): void
    {
        $this->check(InstalledVersions::getPrettyVersion('durable-workflow/workflow') === '2.6.0-rc.1', 'Wrong published Core version');
        $this->check(InstalledVersions::getReference('durable-workflow/workflow') === '59bdfb207f0ecd68261b8776e57042397eb494d4', 'Wrong published Core source');
        $package = realpath(InstalledVersions::getInstallPath('durable-workflow/workflow'));
        $this->check(is_string($package) && str_starts_with($package, base_path('vendor') . '/'), 'Product must be normally installed under vendor');
        $this->check(config('queue.default') === 'database', 'A real database queue is required');
        $this->check(config('workflows.v2.task_dispatch_mode') === 'queue', 'Queue dispatch is required');
        $this->check(config('database.connections.' . config('database.default') . '.database') !== ':memory:', 'A persistent database is required');
        $this->check(WorkflowRun::query()->count() === 0 && WorkflowServiceCall::query()->count() === 0, 'Use a fresh database');
        $this->catalog();
        $cases = [];
        foreach (['shared', 'tenant-a'] as $namespace) {
            foreach (['completed', 'failed', 'target_cancel', 'caller_cancel'] as $scenario) {
                $cases[] = $this->runCase($namespace, $scenario);
                echo json_encode(end($cases), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
            }
        }
        $this->check(WorkflowRun::query()->count() === 16, 'Every original must retain exactly caller and target runs');
        $this->check(DB::table('failed_jobs')->count() === 0, 'Unexpected failed Laravel jobs');
        echo json_encode(['outcome' => 'pass', 'version' => '2.6.0-rc.1', 'source' => InstalledVersions::getReference('durable-workflow/workflow'),
            'cases' => count($cases), 'queue_processes' => count($this->processes), 'distinct_queue_processes' => count(array_unique($this->processes)),
            'runs' => WorkflowRun::query()->count(), 'calls' => WorkflowServiceCall::query()->count()], JSON_THROW_ON_ERROR), "\n";
    }

    private function catalog(): void
    {
        $endpoint = WorkflowServiceEndpoint::query()->create(['namespace' => 'shared', 'endpoint_name' => 'embedded-preview']);
        $service = WorkflowService::query()->create(['namespace' => 'shared', 'workflow_service_endpoint_id' => $endpoint->id, 'service_name' => 'greeter']);
        WorkflowServiceOperation::query()->create([
            'namespace' => 'shared', 'workflow_service_endpoint_id' => $endpoint->id, 'workflow_service_id' => $service->id,
            'operation_name' => 'run', 'operation_mode' => 'sync', 'handler_binding_kind' => 'start_workflow',
            'handler_target_reference' => PublishedNexusTarget::class, 'deadline_policy' => ['timeout_seconds' => 120],
            'cancellation_policy' => ['allow_cancel' => true, 'propagate_to_linked_workflow' => true],
        ]);
    }

    private function runCase(string $namespace, string $scenario): array
    {
        $started = microtime(true);
        $deadline = $started + 30;
        $payload = ['long' => PHP_INT_MAX, 'nothing' => null, 'unicode' => 'λ雪'];
        $caller = WorkflowStub::make(PublishedNexusCaller::class, 'embedded-' . $scenario . '-' . bin2hex(random_bytes(5)), $namespace);
        $caller->start($scenario, $payload, new StartOptions(executionTimeoutSeconds: 120, runTimeoutSeconds: 120));
        $originalRun = $caller->runId();
        $executionDeadline = $caller->run()->getRawOriginal('execution_deadline_at');
        $processStart = count($this->processes);
        $this->worker($deadline);
        $call = WorkflowServiceCall::query()->where('caller_workflow_run_id', $originalRun)->sole();
        $this->check($call->status === 'started' && $caller->refresh()->run()->status === RunStatus::Waiting && $caller->output() === null,
            'A completion-waiting caller must not complete at admission');
        $callDeadline = $call->getRawOriginal('call_deadline_at');
        $target = WorkflowStub::loadRun($call->linked_workflow_run_id);
        $root = null;
        while (! $caller->refresh()->run()->status->isTerminal()) {
            $this->check(microtime(true) < $deadline, 'Original thirty-second observation budget expired');
            $this->worker($deadline);
            if ($root === null && in_array($scenario, ['target_cancel', 'caller_cancel'], true) && $target->refresh()->run()->status === RunStatus::Waiting) {
                $owner = $scenario === 'caller_cancel' ? $caller : $target;
                $root = $owner->requestCancellation('embedded original cancellation', 30)->cancellationContext();
                $duplicate = $owner->requestCancellation('replacement reason', 1)->cancellationContext();
                $this->check($root->toArray() === $duplicate->toArray(), 'Duplicate cancellation replaced its original context');
            }
            usleep(100000);
        }
        $caller = WorkflowStub::loadRun($originalRun);
        $call->refresh();
        $target = WorkflowStub::loadRun($call->linked_workflow_run_id);
        $this->check($caller->runId() === $originalRun, 'Caller run was replaced');
        $this->check($caller->run()->getRawOriginal('execution_deadline_at') === $executionDeadline, 'Caller execution deadline changed');
        $this->check($call->getRawOriginal('call_deadline_at') === $callDeadline, 'Original call deadline changed');
        $this->check($call->caller_workflow_run_id === $originalRun && $call->caller_namespace === $namespace && $call->target_namespace === 'shared', 'Original call binding changed');
        $this->verifyOutcome($caller, $target, $call, $scenario, $payload);
        if ($root !== null) {
            $cleanup = $target->memo()['nexus_cleanup'] ?? null;
            $this->check(is_array($cleanup) && $cleanup['root_request_id'] === $root->rootRequestId
                && $cleanup['cleanup_deadline_at'] === $root->deadline()->toISOString(), 'Target cleanup lost the original root/deadline');
        }
        $events = $caller->run()->historyEvents()->get();
        $this->check($events->filter(static fn ($event) => $event->event_type === HistoryEventType::ServiceCallStarted)->count() === 1, 'Expected one original service start');
        $terminal = $events->filter(static fn ($event) => in_array($event->event_type, [HistoryEventType::ServiceCallCompleted, HistoryEventType::ServiceCallFailed, HistoryEventType::ServiceCallCancelled], true));
        $this->check($terminal->count() === 1, 'Expected exactly one terminal service event');
        $this->check(WorkflowTask::query()->whereIn('workflow_run_id', [$originalRun, $target->runId()])->whereIn('status', ['ready', 'leased'])->count() === 0,
            'Terminal originals retain runnable tasks');
        return ['outcome' => 'pass', 'scenario' => $scenario, 'namespace' => $namespace, 'caller_run' => $originalRun,
            'target_run' => $target->runId(), 'service_call' => $call->id, 'call_status' => $call->status,
            'terminal_event' => $terminal->sole()->event_type->value, 'queue_processes' => count($this->processes) - $processStart,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    private function verifyOutcome(WorkflowStub $caller, WorkflowStub $target, WorkflowServiceCall $call, string $scenario, array $payload): void
    {
        if ($scenario === 'completed') {
            $this->check($call->status === 'completed' && $target->run()->status === RunStatus::Completed, 'Target completion did not converge');
            $this->check($caller->output() === ['status' => 'completed', 'call_id' => $call->id, 'value' => $payload], 'Exact typed result changed');
            return;
        }
        if ($scenario === 'failed') {
            $this->check($call->status === 'failed' && $target->run()->status === RunStatus::Failed, 'Target failure did not converge');
            $this->check($caller->output() === ['error_class' => PublishedNexusFailure::class, 'error_message' => 'embedded service failure'], 'Typed failure changed');
            return;
        }
        $this->check($call->status === 'cancelled' && $target->run()->status === RunStatus::Cancelled, 'Target cancellation did not converge');
        $expected = $scenario === 'caller_cancel' ? RunStatus::Cancelled : RunStatus::Completed;
        $this->check($caller->run()->status === $expected, 'Unexpected caller cancellation outcome');
        if ($scenario === 'target_cancel') {
            $this->check(is_string($caller->output()['error_class'] ?? null), 'Missing workflow-visible cancellation cause');
        }
    }

    private function worker(float $deadline): void
    {
        $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/nexus-workflows.php', 'artisan',
            'queue:work', 'database', '--once', '--queue=default', '--sleep=0', '--tries=1', '--timeout=10'], dirname(__DIR__));
        $process->setTimeout(min(10, max(0.1, $deadline - microtime(true))));
        $process->start();
        $pid = $process->getPid();
        $this->check(is_int($pid), 'No actual queue process was started');
        $this->processes[] = $pid;
        $process->wait();
        $this->check($process->isSuccessful(), 'Queue process failed: ' . substr($process->getErrorOutput() . $process->getOutput(), -2000));
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}

(new PublishedEmbeddedNexusProbe())->run();
