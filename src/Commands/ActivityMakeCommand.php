<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'make:activity')]
class ActivityMakeCommand extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'make:activity';

    /**
     * The name of the console command.
     *
     * This name is used to identify the command during lazy loading.
     *
     * @var string|null
     *
     * @deprecated
     */
    protected static $defaultName = 'make:activity';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new activity class';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Activity';

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return $this->option('v2')
            ? __DIR__ . '/stubs/activity.v2.stub'
            : __DIR__ . '/stubs/activity.stub';
    }

    /**
     * Get the default namespace for the class.
     *
     * @param  string  $rootNamespace
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace . '\\' . config('workflows.workflows_folder', 'Workflows');
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Create the class even if the activity already exists'],
            ['v2', null, InputOption::VALUE_NONE, 'Generate a Workflow\\V2 activity scaffold'],
            ['type', null, InputOption::VALUE_REQUIRED, 'Explicit durable v2 type key'],
        ];
    }

    /**
     * Build the class with the given name.
     *
     * @param  string  $name
     * @return string
     */
    protected function buildClass($name)
    {
        $stub = file_get_contents($this->getStub());

        return str_replace(
            ['{{ namespace }}', '{{ class }}', '{{ type_key }}'],
            [$this->getNamespace($name), class_basename($name), var_export($this->typeKey($name), true)],
            $stub,
        );
    }

    private function typeKey(string $name): string
    {
        $explicitType = trim((string) $this->option('type'));

        if ($explicitType !== '') {
            return $explicitType;
        }

        return (string) Str::of(class_basename($name))
            ->kebab();
    }
}
