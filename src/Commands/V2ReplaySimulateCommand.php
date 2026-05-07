<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Workflow\V2\Support\ReplaySimulation;
use Workflow\V2\Support\ReplayVerification;

#[AsCommand(name: 'workflow:v2:replay-simulate')]
class V2ReplaySimulateCommand extends Command
{
    protected $signature = 'workflow:v2:replay-simulate
        {directory : Directory containing workflow:v2 history-export bundle JSON files}
        {--signing-key= : HMAC key for signature verification (overrides config)}
        {--skip-replay : Verify integrity only; do not replay against current code}
        {--strict-warnings : Treat structural warnings as per-bundle failures}
        {--json : Emit a single machine-readable JSON report}
        {--output= : Write the JSON report to a file instead of stdout}';

    protected $description = 'Verify every Workflow v2 history bundle in a directory and emit one promotion verdict';

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = (new ReplaySimulation())->simulateDirectory(
            directory: (string) $this->argument('directory'),
            signingKey: $this->stringOption('signing-key'),
            skipReplay: (bool) $this->option('skip-replay'),
            strictWarnings: (bool) $this->option('strict-warnings'),
        );

        $this->emit($report);

        return in_array($report['verdict'] ?? null, [
            ReplayVerification::VERDICT_DRIFTED,
            ReplayVerification::VERDICT_FAILED,
        ], true)
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function emit(array $report): void
    {
        if ((bool) $this->option('json') || $this->stringOption('output') !== null) {
            $this->emitJson($report);

            return;
        }

        $this->renderHuman($report);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function emitJson(array $report): void
    {
        try {
            $json = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error($exception->getMessage());

            return;
        }

        $output = $this->stringOption('output');

        if ($output !== null) {
            $this->files->ensureDirectoryExists(dirname($output));
            $this->files->put($output, $json . PHP_EOL);

            return;
        }

        $this->line($json);
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderHuman(array $report): void
    {
        $verdict = (string) ($report['verdict'] ?? ReplayVerification::VERDICT_FAILED);
        $summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];

        $this->line(sprintf('Replay simulation: %s', $this->verdictLabel($verdict)));
        $this->line(sprintf(
            'Bundles: total=%d ok=%d warning=%d drifted=%d failed=%d',
            (int) ($summary['total'] ?? 0),
            (int) ($summary[ReplayVerification::VERDICT_OK] ?? 0),
            (int) ($summary[ReplayVerification::VERDICT_WARNING] ?? 0),
            (int) ($summary[ReplayVerification::VERDICT_DRIFTED] ?? 0),
            (int) ($summary[ReplayVerification::VERDICT_FAILED] ?? 0),
        ));

        foreach (($report['missing_bundles'] ?? []) as $missing) {
            $this->line(sprintf('  [MISSING] %s', (string) $missing));
        }

        foreach (($report['bundles'] ?? []) as $bundle) {
            if (! is_array($bundle)) {
                continue;
            }

            $this->line(sprintf(
                '  [%s] %s promotion=%s',
                strtoupper((string) ($bundle['verdict'] ?? ReplayVerification::VERDICT_FAILED)),
                (string) ($bundle['bundle_path'] ?? 'n/a'),
                (string) ($bundle['promotion_decision'] ?? ReplayVerification::PROMOTION_BLOCK_AND_INVESTIGATE),
            ));
        }
    }

    private function verdictLabel(string $verdict): string
    {
        return match ($verdict) {
            ReplayVerification::VERDICT_OK => '<info>OK</info>',
            ReplayVerification::VERDICT_WARNING => '<comment>WARNING</comment>',
            ReplayVerification::VERDICT_DRIFTED => '<error>DRIFTED</error>',
            ReplayVerification::VERDICT_FAILED => '<error>FAILED</error>',
            default => $verdict,
        };
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
}
