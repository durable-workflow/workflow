<?php

declare(strict_types=1);

namespace Workflow;

use Illuminate\Support\Carbon;
use Illuminate\Support\Traits\Macroable;
use LimitIterator;
use ReflectionClass;
use SplFileObject;
use Workflow\Events\WorkflowFailed;
use Workflow\Events\WorkflowStarted;
use Workflow\Exceptions\TransitionNotFound;
use Workflow\Models\StoredWorkflow;
use Workflow\Serializers\Serializer;
use Workflow\States\WorkflowCompletedStatus;
use Workflow\States\WorkflowCreatedStatus;
use Workflow\States\WorkflowFailedStatus;
use Workflow\States\WorkflowPendingStatus;
use Workflow\Traits\Awaits;
use Workflow\Traits\AwaitWithTimeouts;
use Workflow\Traits\Continues;
use Workflow\Traits\Fakes;
use Workflow\Traits\SideEffects;
use Workflow\Traits\Timers;
use Workflow\Traits\Versions;

final class WorkflowStub
{
    use Awaits;
    use AwaitWithTimeouts;
    use Continues;
    use Fakes;
    use Macroable;
    use SideEffects;
    use Timers;
    use Versions;

    public const DEFAULT_VERSION = -1;

    private static ?\stdClass $context = null;

    private static array $signalMethodCache = [];

    private static array $queryMethodCache = [];

    private static array $updateMethodCache = [];

    private static array $defaultPropertiesCache = [];

    private function __construct(
        protected $storedWorkflow
    ) {
        self::setContext([
            'storedWorkflow' => $storedWorkflow,
            'index' => 0,
            'now' => Carbon::now(),
            'replaying' => false,
        ]);
    }

    public function __call($method, $arguments)
    {
        if (self::isSignalMethod($this->storedWorkflow->class, $method)) {
            $activeWorkflow = $this->storedWorkflow->active();

            $activeWorkflow->signals()
                ->create([
                    'method' => $method,
                    'arguments' => Serializer::serialize($arguments),
                ]);

            $activeWorkflow->toWorkflow();

            if (static::faked()) {
                $this->resume();
                return;
            }

            return Signal::dispatch($activeWorkflow, self::connection(), self::queue());
        }

        if (self::isQueryMethod($this->storedWorkflow->class, $method)) {
            $activeWorkflow = $this->storedWorkflow->active();

            return (new $activeWorkflow->class($activeWorkflow, ...$activeWorkflow->workflowArguments()))
                ->query($method);
        }

        if (self::isUpdateMethod($this->storedWorkflow->class, $method)) {
            $activeWorkflow = $this->storedWorkflow->active();

            $workflow = new $activeWorkflow->class($activeWorkflow, ...$activeWorkflow->workflowArguments());
            $result = $workflow->query($method);

            if ($workflow->outboxWasConsumed) {
                $activeWorkflow->signals()
                    ->create([
                        'method' => $method,
                        'arguments' => Serializer::serialize($arguments),
                    ]);

                $activeWorkflow->toWorkflow();

                if (static::faked()) {
                    $this->resume();
                    return $result;
                }

                Signal::dispatch($activeWorkflow, self::connection(), self::queue());
            }

            return $result;
        }
    }

    public static function connection()
    {
        return self::$context->storedWorkflow->effectiveConnection();
    }

    public static function queue()
    {
        return self::$context->storedWorkflow->effectiveQueue();
    }

    public static function getDefaultProperties(string $class): array
    {
        if (! isset(self::$defaultPropertiesCache[$class])) {
            self::$defaultPropertiesCache[$class] = (new ReflectionClass($class))->getDefaultProperties();
        }

        return self::$defaultPropertiesCache[$class];
    }

    public static function make($class): static
    {
        $storedWorkflow = config('workflows.stored_workflow_model', StoredWorkflow::class)::create([
            'class' => $class,
        ]);

        return new self($storedWorkflow);
    }

    public static function load($id)
    {
        return static::fromStoredWorkflow(
            config('workflows.stored_workflow_model', StoredWorkflow::class)::findOrFail($id)
        );
    }

    public static function fromStoredWorkflow(StoredWorkflow $storedWorkflow): static
    {
        return new self($storedWorkflow);
    }

    public static function getContext(): \stdClass
    {
        return self::$context;
    }

    public static function setContext($context): void
    {
        self::$context = (object) $context;
    }

    public static function now()
    {
        return self::getContext()->now;
    }

    public function id()
    {
        return $this->storedWorkflow->id;
    }

    public function logs()
    {
        return $this->storedWorkflow->active()
            ->logs;
    }

    public function exceptions()
    {
        return $this->storedWorkflow->active()
            ->exceptions;
    }

    public function output()
    {
        $activeWorkflow = $this->storedWorkflow->active();

        if ($activeWorkflow->output === null) {
            return null;
        }

        return Serializer::unserialize($activeWorkflow->output);
    }

    public function completed(): bool
    {
        return $this->status() === WorkflowCompletedStatus::class;
    }

    public function created(): bool
    {
        return $this->status() === WorkflowCreatedStatus::class;
    }

    public function failed(): bool
    {
        return $this->status() === WorkflowFailedStatus::class;
    }

    public function running(): bool
    {
        return ! in_array($this->status(), [WorkflowCompletedStatus::class, WorkflowFailedStatus::class], true);
    }

    public function status(): string|bool
    {
        return $this->storedWorkflow->active()
            ->status::class;
    }

    public function fresh(): static
    {
        $this->storedWorkflow->refresh();

        return $this;
    }

    public function resume(): void
    {
        $this->fresh()
            ->dispatch();
    }

    public function start(...$arguments): void
    {
        $fallbackOptions = $this->storedWorkflow->workflowOptions();

        $metadata = WorkflowMetadata::fromStartArguments($arguments, $fallbackOptions);

        $this->storedWorkflow->arguments = Serializer::serialize($metadata->toArray());

        $this->dispatch();
    }

    public function startAsChild(StoredWorkflow $parentWorkflow, int $index, $now, ...$arguments): void
    {
        $this->storedWorkflow->parents()
            ->detach();

        $this->storedWorkflow->parents()
            ->attach($parentWorkflow, [
                'parent_index' => $index,
                'parent_now' => $now,
            ]);

        $this->start(...$arguments);
    }

    public function fail($exception): void
    {
        $this->storedWorkflow->exceptions()
            ->create([
                'class' => $this->storedWorkflow->class,
                'exception' => Serializer::serialize($exception),
            ]);

        $this->storedWorkflow->status->transitionTo(WorkflowFailedStatus::class);

        $file = new SplFileObject($exception->getFile());
        $iterator = new LimitIterator($file, max(0, $exception->getLine() - 4), 7);

        WorkflowFailed::dispatch($this->storedWorkflow->id, json_encode([
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'line' => $exception->getLine(),
            'file' => $exception->getFile(),
            'trace' => $exception->getTrace(),
            'snippet' => array_slice(iterator_to_array($iterator), 0, 7),
        ]), now()
            ->format('Y-m-d\TH:i:s.u\Z'));

        $this->storedWorkflow->parents()
            ->each(static function ($parentWorkflow) use ($exception) {
                if (
                    $parentWorkflow->pivot->parent_index === StoredWorkflow::CONTINUE_PARENT_INDEX
                    || $parentWorkflow->pivot->parent_index === StoredWorkflow::ACTIVE_WORKFLOW_INDEX
                ) {
                    try {
                        $parentWorkflow->toWorkflow()
                            ->fail($exception);
                    } catch (TransitionNotFound) {
                        return;
                    }
                    return;
                }

                $file = new SplFileObject($exception->getFile());
                $iterator = new LimitIterator($file, max(0, $exception->getLine() - 4), 7);

                $throwable = [
                    'class' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                    'line' => $exception->getLine(),
                    'file' => $exception->getFile(),
                    'trace' => collect($exception->getTrace())
                        ->filter(static fn ($trace) => Serializer::serializable($trace))
                        ->toArray(),
                    'snippet' => array_slice(iterator_to_array($iterator), 0, 7),
                ];

                $parentWf = $parentWorkflow->toWorkflow();

                Exception::dispatch(
                    $parentWorkflow->pivot->parent_index,
                    $parentWorkflow->pivot->parent_now,
                    $parentWorkflow,
                    $throwable,
                    $parentWf->connection(),
                    $parentWf->queue()
                );
            });
    }

    public function next($index, $now, $class, $result, bool $shouldSignal = true): void
    {
        try {
            $this->storedWorkflow->createLog([
                'index' => $index,
                'now' => $now,
                'class' => $class,
                'result' => Serializer::serialize($result),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            // already logged
        }

        if ($shouldSignal) {
            $this->dispatch();
        }
    }

    public static function isUpdateMethod(string $class, string $method): bool
    {
        if (! isset(self::$updateMethodCache[$class])) {
            self::$updateMethodCache[$class] = [];
            foreach ((new ReflectionClass($class))->getMethods() as $reflectionMethod) {
                foreach ($reflectionMethod->getAttributes() as $attribute) {
                    if ($attribute->getName() === UpdateMethod::class) {
                        self::$updateMethodCache[$class][$reflectionMethod->getName()] = true;
                        break;
                    }
                }
            }
        }

        return self::$updateMethodCache[$class][$method] ?? false;
    }

    private static function isSignalMethod(string $class, string $method): bool
    {
        if (! isset(self::$signalMethodCache[$class])) {
            self::$signalMethodCache[$class] = [];
            foreach ((new ReflectionClass($class))->getMethods() as $reflectionMethod) {
                foreach ($reflectionMethod->getAttributes() as $attribute) {
                    if ($attribute->getName() === SignalMethod::class) {
                        self::$signalMethodCache[$class][$reflectionMethod->getName()] = true;
                        break;
                    }
                }
            }
        }

        return self::$signalMethodCache[$class][$method] ?? false;
    }

    private static function isQueryMethod(string $class, string $method): bool
    {
        if (! isset(self::$queryMethodCache[$class])) {
            self::$queryMethodCache[$class] = [];
            foreach ((new ReflectionClass($class))->getMethods() as $reflectionMethod) {
                foreach ($reflectionMethod->getAttributes() as $attribute) {
                    if ($attribute->getName() === QueryMethod::class) {
                        self::$queryMethodCache[$class][$reflectionMethod->getName()] = true;
                        break;
                    }
                }
            }
        }

        return self::$queryMethodCache[$class][$method] ?? false;
    }

    private function dispatch(): void
    {
        if ($this->created()) {
            WorkflowStarted::dispatch(
                $this->storedWorkflow->id,
                $this->storedWorkflow->class,
                json_encode($this->storedWorkflow->workflowArguments()),
                now()
                    ->format('Y-m-d\TH:i:s.u\Z')
            );
        }

        try {
            $this->storedWorkflow->status->transitionTo(WorkflowPendingStatus::class);
        } catch (TransitionNotFound $exception) {
            $this->storedWorkflow->refresh();

            if ($this->status() !== WorkflowPendingStatus::class) {
                throw $exception;
            }
        }

        $dispatch = static::faked() ? 'dispatchSync' : 'dispatch';

        $this->storedWorkflow->class::$dispatch(
            $this->storedWorkflow,
            ...$this->storedWorkflow->workflowArguments()
        );
    }
}
