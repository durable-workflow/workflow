<?php

declare(strict_types=1);

namespace Tests\Unit\States;

use Exception;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\TestCase;
use Workflow\Events\StateChanged;
use Workflow\Exceptions\TransitionNotFound;
use Workflow\States\State;
use Workflow\States\StateCaster;
use Workflow\States\StateConfig;
use Workflow\States\StateMachine;
use Workflow\States\WorkflowRunningStatus;
use Workflow\States\WorkflowStatus;

final class StateInfrastructureTest extends TestCase
{
    public function testTransitionNotFoundProvidesMetadata(): void
    {
        $exception = TransitionNotFound::make('from-state', 'to-state', StateInfraModel::class);

        $this->assertSame('from-state', $exception->getFrom());
        $this->assertSame('to-state', $exception->getTo());
        $this->assertSame(StateInfraModel::class, $exception->getModelClass());
    }

    public function testStateMachineInitializesAppliesAndListsTransitionableStates(): void
    {
        $stateMachine = new StateMachine();
        $stateMachine->addState('draft');
        $stateMachine->addState('published');
        $stateMachine->addTransition('publish', 'draft', 'published');

        $stateMachine->initialize();
        $this->assertSame('draft', $stateMachine->getCurrentState());
        $this->assertTrue($stateMachine->canApply('publish'));

        $stateMachine->apply('publish');
        $this->assertSame('published', $stateMachine->getCurrentState());
        $this->assertSame(['published'], $stateMachine->transitionableStates('draft'));
        $this->assertSame([], $stateMachine->transitionableStates('missing'));

        $stateMachine->initialize('draft');
        $this->assertSame('draft', $stateMachine->getCurrentState());
        $this->assertFalse($stateMachine->canApply('publish-missing'));
    }

    public function testStateMachineThrowsWhenTransitionCannotBeApplied(): void
    {
        $stateMachine = new StateMachine();
        $stateMachine->addState('draft');
        $stateMachine->initialize('draft');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Transition not found.');

        $stateMachine->apply('publish');
    }

    public function testStateConfigSupportsArraysAndTransitionLookup(): void
    {
        $config = new StateConfig(StateInfraState::class);

        $this->assertSame($config, $config->ignoreSameState());
        $this->assertSame(
            $config,
            $config->allowTransition(
                [StateInfraInitialState::class, StateInfraNextState::class],
                StateInfraTerminalState::class
            )
        );
        $this->assertSame(
            $config,
            $config->allowTransitions([
                [StateInfraTerminalState::class, StateInfraNextState::class],
                [StateInfraNextState::class, StateInfraInitialState::class],
            ])
        );
        $this->assertSame(
            $config,
            $config->registerState([StateInfraInitialState::class, StateInfraNextState::class])
        );

        $this->assertTrue($config->isTransitionAllowed(StateInfraInitialState::$name, StateInfraInitialState::$name));
        $this->assertContains(
            StateInfraTerminalState::$name,
            $config->transitionableStates(StateInfraInitialState::$name)
        );
        $this->assertContains(StateInfraInitialState::class, $config->registeredStates);
        $this->assertContains(StateInfraNextState::class, $config->registeredStates);
    }

    public function testStateConfigRejectsInvalidTransitionFromState(): void
    {
        $config = new StateConfig(StateInfraState::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stdClass does not extend');

        $config->allowTransition(\stdClass::class, StateInfraNextState::class);
    }

    public function testStateConfigRejectsInvalidTransitionToState(): void
    {
        $config = new StateConfig(StateInfraState::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stdClass does not extend');

        $config->allowTransition(StateInfraInitialState::class, \stdClass::class);
    }

    public function testStateConfigRejectsInvalidRegisteredState(): void
    {
        $config = new StateConfig(StateInfraState::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stdClass does not extend');

        $config->registerState(\stdClass::class);
    }

    public function testStateCasterCastsSetAndSerializesValues(): void
    {
        $stateCaster = new StateCaster(StateInfraState::class);
        $model = new StateInfraModel();

        $this->assertNull($stateCaster->get($model, 'status', null, []));

        $state = $stateCaster->get($model, 'status', StateInfraInitialState::class, []);
        $this->assertInstanceOf(StateInfraInitialState::class, $state);
        $this->assertSame('status', $state->getField());

        $this->assertNull($stateCaster->set($model, 'status', null, []));
        $this->assertSame(StateInfraInitialState::$name, $stateCaster->set($model, 'status', $state, []));
        $this->assertSame(
            StateInfraNextState::$name,
            $stateCaster->set($model, 'status', StateInfraNextState::class, [])
        );
        $this->assertSame(StateInfraInitialState::$name, $stateCaster->serialize($model, 'status', $state, []));
        $this->assertSame('raw', $stateCaster->serialize($model, 'status', 'raw', []));
    }

    public function testStateCasterRejectsUnknownValues(): void
    {
        $stateCaster = new StateCaster(StateInfraState::class);
        $model = new StateInfraModel();

        try {
            $stateCaster->get($model, 'status', 'unknown-state', []);
            $this->fail('Expected invalid state exception to be thrown for get().');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Unknown state `unknown-state`', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown state `unknown-state`');
        $stateCaster->set($model, 'status', 'unknown-state', []);
    }

    public function testStateUtilityMethods(): void
    {
        $model = new StateInfraModel();
        $state = new StateInfraInitialState($model);
        $state->setField('status');

        $this->assertSame(StateInfraInitialState::$name, (string) $state);
        $this->assertSame($model, $state->getModel());
        $this->assertSame('status', $state->getField());
        $this->assertSame(StateInfraInitialState::$name, $state->jsonSerialize());
        $this->assertInstanceOf(StateCaster::class, StateInfraState::castUsing([]));
        $this->assertSame(StateInfraInitialState::class, StateInfraState::resolveStateClass($state));
        $this->assertSame(
            StateInfraInitialState::class,
            StateInfraState::resolveStateClass(StateInfraInitialState::class)
        );
        $this->assertSame(
            StateInfraInitialState::$name,
            StateInfraState::resolveStateClass(StateInfraInitialState::$name)
        );
        $this->assertNull(StateInfraState::resolveStateClass(null));
        $this->assertNull(StateInfraState::resolveStateClass(123));
        $this->assertSame('custom-state', StateInfraState::resolveStateClass('custom-state'));
        $this->assertSame(
            WorkflowRunningStatus::class,
            WorkflowStatus::resolveStateClass(WorkflowRunningStatus::$name)
        );
        $this->assertNull(StateInfraState::all()->get(StateInfraInitialState::$name));
        $this->assertInstanceOf(
            StateInfraInitialState::class,
            StateInfraState::make(StateInfraInitialState::class, $model)
        );
    }

    public function testStateTransitionsAndEqualityChecks(): void
    {
        Event::fake([StateChanged::class]);

        $model = new StateInfraModel();
        $state = new StateInfraInitialState($model);
        $state->setField('status');
        $model->status = $state;

        $this->assertTrue($state->canTransitionTo(StateInfraNextState::class));
        $this->assertFalse($state->canTransitionTo(StateInfraTerminalState::class));
        $this->assertTrue($state->equals(StateInfraInitialState::class));
        $this->assertTrue($state->equals(new StateInfraInitialState($model)));
        $this->assertFalse($state->equals(StateInfraNextState::class));
        $this->assertFalse($state->equals(new StateInfraNextState($model)));

        $result = $state->transitionTo(StateInfraNextState::class);
        $this->assertSame($model, $result);
        $this->assertTrue($model->saved);
        $this->assertInstanceOf(StateInfraNextState::class, $model->status);
        $this->assertSame('status', $model->status->getField());
        Event::assertDispatched(StateChanged::class, static function (StateChanged $event) use ($model, $state) {
            return $event->initialState === $state
                && $event->finalState instanceof StateInfraNextState
                && $event->model === $model
                && $event->field === 'status';
        });

        $model->saved = false;
        $nextState = $model->status;
        $nextState->transitionTo(new StateInfraTerminalState($model));

        $this->assertTrue($model->saved);
        $this->assertInstanceOf(StateInfraTerminalState::class, $model->status);
        Event::assertDispatched(StateChanged::class, static function (StateChanged $event) use ($model, $nextState) {
            return $event->initialState === $nextState
                && $event->finalState instanceof StateInfraTerminalState
                && $event->model === $model
                && $event->field === 'status';
        });

        try {
            $model->status->transitionTo(StateInfraInitialState::class);
            $this->fail('Expected transition to fail.');
        } catch (TransitionNotFound $exception) {
            $this->assertSame(StateInfraTerminalState::$name, $exception->getFrom());
            $this->assertSame(StateInfraInitialState::$name, $exception->getTo());
            $this->assertSame(StateInfraModel::class, $exception->getModelClass());
        }
    }

    public function testStateRejectsUnknownTransitionTarget(): void
    {
        $model = new StateInfraModel();
        $state = new StateInfraInitialState($model);
        $state->setField('status');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not extend');

        $state->canTransitionTo('unknown-transition');
    }

    public function testStateMakeRejectsUnknownState(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not extend');

        StateInfraState::make('unknown-state', new StateInfraModel());
    }

    public function testStateMappingHandlesEvalAndMissingDirectoryScenarios(): void
    {
        $suffix = str_replace('.', '', uniqid('', true));

        $evalClass = 'StateInfraEval' . $suffix;
        eval('namespace ' . __NAMESPACE__ . '; abstract class ' . $evalClass . ' extends \\Workflow\\States\\State {}');
        $evalClassName = __NAMESPACE__ . '\\' . $evalClass;
        $this->assertSame([], $evalClassName::getStateMapping()->all());

        $namespace = __NAMESPACE__ . '\\Deleted' . $suffix;
        $directory = $this->createTempDirectory('state-deleted-');
        $class = 'DeletedState' . $suffix;
        $file = $directory . '/' . $class . '.php';

        file_put_contents(
            $file,
            "<?php\nnamespace {$namespace};\nabstract class {$class} extends \\Workflow\\States\\State {}\n"
        );
        require_once $file;

        unlink($file);
        rmdir($directory);

        $className = $namespace . '\\' . $class;
        $this->assertSame([], $className::getStateMapping()->all());
    }

    public function testStateMappingSkipsUnsupportedFilesAndIncludesRegisteredStates(): void
    {
        $suffix = str_replace('.', '', uniqid('', true));
        $namespace = __NAMESPACE__ . '\\Scan' . $suffix;
        $directory = $this->createTempDirectory('state-scan-');

        $baseClass = 'ScanBase' . $suffix;
        $alphaClass = 'ScanAlpha' . $suffix;
        $registeredClass = 'ScanRegistered' . $suffix;
        $irrelevantClass = 'ScanIrrelevant' . $suffix;

        file_put_contents(
            $directory . '/' . $baseClass . '.php',
            "<?php\nnamespace {$namespace};\nabstract class {$baseClass} extends \\Workflow\\States\\State\n{\n    public static function config(): \\Workflow\\States\\StateConfig\n    {\n        return parent::config()->registerState({$registeredClass}::class);\n    }\n}\n"
        );
        file_put_contents(
            $directory . '/' . $alphaClass . '.php',
            "<?php\nnamespace {$namespace};\nfinal class {$alphaClass} extends {$baseClass}\n{\n    public static string \$name = 'scan-alpha-{$suffix}';\n}\n"
        );
        file_put_contents(
            $directory . '/' . $registeredClass . '.php',
            "<?php\nnamespace {$namespace};\nfinal class {$registeredClass} extends {$baseClass}\n{\n    public static string \$name = 'scan-registered-{$suffix}';\n}\n"
        );
        file_put_contents(
            $directory . '/' . $irrelevantClass . '.php',
            "<?php\nnamespace {$namespace};\nfinal class {$irrelevantClass} {}\n"
        );
        file_put_contents($directory . '/NoClass' . $suffix . '.php', "<?php\n// no class here\n");
        file_put_contents($directory . '/notes-' . $suffix . '.txt', "not a php class\n");

        require_once $directory . '/' . $baseClass . '.php';
        require_once $directory . '/' . $alphaClass . '.php';
        require_once $directory . '/' . $registeredClass . '.php';
        require_once $directory . '/' . $irrelevantClass . '.php';

        $baseClassName = $namespace . '\\' . $baseClass;
        $alphaClassName = $namespace . '\\' . $alphaClass;
        $registeredClassName = $namespace . '\\' . $registeredClass;

        $mapping = $baseClassName::getStateMapping()->all();

        $this->assertSame($alphaClassName, $mapping['scan-alpha-' . $suffix] ?? null);
        $this->assertSame($registeredClassName, $mapping['scan-registered-' . $suffix] ?? null);

        $this->removeDirectory($directory);
    }

    private function createTempDirectory(string $prefix): string
    {
        $directory = sys_get_temp_dir() . '/' . $prefix . str_replace('.', '', uniqid('', true));

        if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Could not create temporary directory: ' . $directory);
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $files = scandir($directory);

        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $directory . '/' . $file;

            if (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}

final class StateInfraModel
{
    public $status = null;

    public bool $saved = false;

    public function save(): void
    {
        $this->saved = true;
    }
}

abstract class StateInfraState extends State
{
    public static function config(): StateConfig
    {
        return parent::config()
            ->default(StateInfraInitialState::class)
            ->allowTransition(StateInfraInitialState::class, StateInfraNextState::class)
            ->allowTransition(StateInfraNextState::class, StateInfraTerminalState::class)
            ->registerState(StateInfraRegisteredState::class);
    }
}

final class StateInfraInitialState extends StateInfraState
{
    public static string $name = 'infra-initial';
}

final class StateInfraNextState extends StateInfraState
{
    public static string $name = 'infra-next';
}

final class StateInfraTerminalState extends StateInfraState
{
    public static string $name = 'infra-terminal';
}

final class StateInfraRegisteredState extends StateInfraState
{
    public static string $name = 'infra-registered';
}

abstract class StateInfraOtherState extends State
{
}

final class StateInfraOtherInitialState extends StateInfraOtherState
{
    public static string $name = 'infra-other-initial';
}
