<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Workflow\V2\WorkflowStub;

#[AsCommand(name: 'workflow:v2:history-export')]
class V2HistoryExportCommand extends Command
{
    protected $signature = 'workflow:v2:history-export
        {target : Workflow instance id, or a workflow run id when --run is used}
        {--run : Treat the target argument as a workflow run id}
        {--run-id= : Selected run id when the target argument is a workflow instance id}
        {--output= : Write the JSON bundle to a file instead of stdout}
        {--pretty : Pretty-print the JSON bundle}';

    protected $description = 'Export a Workflow v2 history bundle for offline debugging or archival handoff';

    public function __construct(
        private readonly Filesystem $files
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $target = trim((string) $this->argument('target'));
        $runId = $this->stringOption('run-id');
        $treatTargetAsRun = (bool) $this->option('run');

        if ($target === '') {
            $this->error('The target workflow instance or run id is required.');

            return self::FAILURE;
        }

        if ($treatTargetAsRun && $runId !== null) {
            $this->error('Use either --run or --run-id, not both.');

            return self::FAILURE;
        }

        try {
            $workflow = $treatTargetAsRun
                ? WorkflowStub::loadRun($target)
                : WorkflowStub::loadSelection($target, $runId);
            $bundle = $workflow->historyExport();
            $json = $this->encode($bundle);
        } catch (ModelNotFoundException) {
            $this->error($this->notFoundMessage($target, $runId, $treatTargetAsRun));

            return self::FAILURE;
        } catch (LogicException|JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $outputPath = $this->stringOption('output');

        if ($outputPath === null) {
            $this->line($json);

            return self::SUCCESS;
        }

        $this->writeBundle($outputPath, $json);
        $this->info(sprintf(
            'Exported workflow history for run [%s] to [%s].',
            $bundle['workflow']['run_id'] ?? $target,
            $outputPath,
        ));

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $bundle
     */
    private function encode(array $bundle): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

        if ((bool) $this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $json = json_encode($bundle, $flags);

        if (! is_string($json)) {
            throw new JsonException('Failed to encode workflow history export.');
        }

        return $json;
    }

    private function writeBundle(string $path, string $json): void
    {
        $directory = dirname($path);

        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }

        $this->files->put($path, $json . PHP_EOL);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function notFoundMessage(string $target, ?string $runId, bool $targetIsRun): string
    {
        if ($targetIsRun) {
            return sprintf('Workflow run [%s] was not found.', $target);
        }

        if ($runId !== null) {
            return sprintf('Workflow run [%s] was not found for workflow instance [%s].', $runId, $target);
        }

        return sprintf('Workflow instance [%s] was not found.', $target);
    }
}
