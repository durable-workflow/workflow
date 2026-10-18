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
use Workflow\V2\Contracts\ExternalPayloadStoragePolicy;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowService;
use Workflow\V2\Models\WorkflowServiceCall;
use Workflow\V2\Models\WorkflowServiceEndpoint;
use Workflow\V2\Models\WorkflowServiceOperation;
use Workflow\V2\Models\WorkflowTask;
use Workflow\V2\StartOptions;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\V2\WorkflowStub;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/nexus-workflows.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

final class PublishedEmbeddedNexusProbe
{
    private const PACKAGE_NAME = 'durable-workflow/workflow';

    private array $processes = [];

    public function run(): void
    {
        $this->check(PHP_INT_SIZE === 8, 'The int64 profile requires 64-bit PHP');
        $this->check(InstalledVersions::getPrettyVersion(self::PACKAGE_NAME) === '2.6.1', 'Wrong published Core version');
        $this->check(InstalledVersions::getReference(self::PACKAGE_NAME) === '1f72c41fa527685d9bcc75ea86f02ebe3cc9adfb', 'Wrong published Core source');
        $package = realpath(InstalledVersions::getInstallPath(self::PACKAGE_NAME));
        $this->check(is_string($package) && str_starts_with($package, base_path('vendor') . '/'), 'Product must be normally installed under vendor');
        $this->check(config('queue.default') === 'database', 'A real database queue is required');
        $this->check(config('workflows.v2.task_dispatch_mode') === 'queue', 'Queue dispatch is required');
        $this->check(app(ExternalPayloadStoragePolicy::class)->thresholdBytesFor('shared') === 1024, 'The real local external-payload policy must be configured');
        $this->check($this->databasePath() !== ':memory:', 'A persistent database is required');
        $this->check(WorkflowRun::query()->count() === 0 && WorkflowServiceCall::query()->count() === 0, 'Use a fresh database');
        $this->catalog();
        $cases = [];
        foreach (['shared', 'tenant-a'] as $namespace) {
            foreach (['completed', 'failed', 'target_cancel', 'caller_cancel', 'backend_interruption', 'external'] as $scenario) {
                $cases[] = $this->runCase($namespace, $scenario);
                echo json_encode(end($cases), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), "\n";
            }
        }
        $this->check(WorkflowRun::query()->count() === 24, 'Every original must retain exactly caller and target runs');
        $this->check(DB::table('failed_jobs')->count() === 0, 'Unexpected failed Laravel jobs');
        echo json_encode(['outcome' => 'pass', 'version' => '2.6.1', 'source' => InstalledVersions::getReference(self::PACKAGE_NAME),
            'php' => PHP_VERSION, 'framework' => app()->version(), 'composer_lock_sha256' => hash_file('sha256', base_path('composer.lock')),
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
        if ($scenario === 'external') {
            $payload['large'] = str_repeat('external-λ雪', 8192);
        }
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
        if ($scenario === 'backend_interruption') {
            $this->interruptBackend($deadline);
        }
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
        $this->coldRead($caller);
        if ($scenario === 'external') {
            $this->check(ExternalPayloads::isStoredReference($caller->run()->getRawOriginal('arguments'))
                && ExternalPayloads::isStoredReference($target->run()->getRawOriginal('output')),
                'The external case must retain actual argument/result references');
        }
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
        if (in_array($scenario, ['completed', 'backend_interruption', 'external'], true)) {
            $this->check($call->status === 'completed' && $target->run()->status === RunStatus::Completed, 'Target completion did not converge');
            $this->check($caller->output() === ['status' => 'completed', 'call_id' => $call->id, 'value' => $payload], 'Exact typed result changed');
            return;
        }
        if ($scenario === 'failed') {
            $this->check($call->status === 'failed' && $target->run()->status === RunStatus::Failed, 'Target failure did not converge');
            $output = $caller->output();
            $this->check(($output['error_type'] ?? null) === PublishedNexusFailure::class
                && ($output['error_message'] ?? null) === 'embedded service failure'
                && ($call->outcome_metadata['caller_observed_error_type'] ?? null) === PublishedNexusFailure::class,
                'Typed failure changed: ' . json_encode(['output' => $output, 'cause' => $call->outcome_metadata], JSON_THROW_ON_ERROR));
            return;
        }
        $this->check($call->status === 'cancelled' && $target->run()->status === RunStatus::Cancelled, 'Target cancellation did not converge');
        $expected = $scenario === 'caller_cancel' ? RunStatus::Cancelled : RunStatus::Completed;
        $this->check($caller->run()->status === $expected, 'Unexpected caller cancellation outcome');
        if ($scenario === 'target_cancel') {
            $this->check(is_string($caller->output()['error_class'] ?? null), 'Missing workflow-visible cancellation cause');
        }
    }

    private function interruptBackend(float $deadline): void
    {
        $path = realpath($this->databasePath());
        $this->check(is_string($path) && str_starts_with($path, '/state/'), 'Only the disposable source database may be interrupted');
        $this->check(DB::select('PRAGMA journal_mode')[0]->journal_mode === 'delete', 'This fault control requires DELETE mode');
        $before = hash_file('sha256', $path);
        $mode = fileperms($path) & 0777;
        try {
            $this->check(chmod($path, 0000), 'Could not interrupt the disposable backend');
            $this->worker($deadline, true);
        } finally {
            chmod($path, $mode);
        }
        $this->check(hash_file('sha256', $path) === $before, 'Failed pre-claim access changed the database');
    }

    private function coldRead(WorkflowStub $caller): void
    {
        $path = $this->databasePath();
        $before = hash_file('sha256', $path);
        $process = new Process([PHP_BINARY, __FILE__, '--inspect', $caller->runId()], dirname(__DIR__));
        $process->setTimeout(10);
        $process->mustRun();
        $read = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->check($read['run_id'] === $caller->runId()
            && $read['output_sha256'] === hash('sha256', serialize($caller->output()))
            && $read['status'] === $caller->run()->status->value, 'Cold reader changed the original typed result');
        $this->check(hash_file('sha256', $path) === $before, 'Cold reads changed database bytes');
    }

    private function worker(float $deadline, bool $expectBackendRefusal = false): void
    {
        $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/nexus-workflows.php', 'artisan',
            'queue:work', 'database', '--once', '--queue=default', '--sleep=0', '--tries=1', '--timeout=10'], dirname(__DIR__));
        $process->setTimeout(min(10, max(0.1, $deadline - microtime(true))));
        $process->start();
        $pid = $process->getPid();
        $this->check(is_int($pid), 'No actual queue process was started');
        $this->processes[] = $pid;
        $process->wait();
        if ($expectBackendRefusal) {
            // Laravel reports a queue-pop database exception and can still
            // return exit 0 from --once; the actual refusal and unchanged
            // database prove that no task was claimed.
            $diagnostic = $process->getErrorOutput() . $process->getOutput();
            $this->check(str_contains($diagnostic, 'unable to open database file'),
                'Missing pre-claim backend refusal: exit=' . $process->getExitCode() . ' ' . substr($diagnostic, -1500));
            return;
        }
        $this->check($process->isSuccessful(), 'Queue process failed: ' . substr($process->getErrorOutput() . $process->getOutput(), -2000));
    }

    private function databasePath(): string
    {
        return config('database.connections.' . config('database.default') . '.database');
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new UnexpectedValueException($message);
        }
    }
}

if (($argv[1] ?? null) === '--inspect') {
    $workflow = WorkflowStub::loadRun($argv[2]);
    echo json_encode(['run_id' => $workflow->runId(), 'status' => $workflow->run()->status->value,
        'output_sha256' => hash('sha256', serialize($workflow->output())), 'process_id' => getmypid()], JSON_THROW_ON_ERROR), "\n";
} else {
    (new PublishedEmbeddedNexusProbe())->run();
}
