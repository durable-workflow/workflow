<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Workflow\V2\Support\EmbeddedV2HistoryImport;

class V2HistoryImportCommand extends Command
{
    protected $signature = 'workflow:v2:history-import
        {bundle : Path to a workflow:v2:history-export JSON bundle}
        {--namespace= : Import into this target namespace instead of the bundle namespace}
        {--dry-run : Validate and report without writing rows}
        {--import-id= : Operator-supplied audit id for this import}
        {--signing-key= : HMAC signing key used to verify the bundle signature}
        {--require-signature : Reject bundles without a verified HMAC signature}
        {--json : Emit the import report as JSON}';

    protected $description = 'Import an embedded v2 history bundle into the current v2 store';

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('bundle');

        if (! $this->files->isFile($path)) {
            $this->error(sprintf('History bundle [%s] was not found.', $path));

            return self::FAILURE;
        }

        try {
            $decoded = json_decode($this->files->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! is_array($decoded)) {
            $this->error('History bundle JSON must decode to an object.');

            return self::FAILURE;
        }

        $report = EmbeddedV2HistoryImport::import($decoded, [
            'dry_run' => (bool) $this->option('dry-run'),
            'namespace' => $this->stringOption('namespace'),
            'import_id' => $this->stringOption('import-id'),
            'signing_key' => $this->stringOption('signing-key'),
            'require_signature' => (bool) $this->option('require-signature'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return in_array($report['status'] ?? null, ['imported', 'already_imported', 'dry_run'], true)
                ? self::SUCCESS
                : self::FAILURE;
        }

        $this->renderReport($report);

        return in_array($report['status'] ?? null, ['imported', 'already_imported', 'dry_run'], true)
            ? self::SUCCESS
            : self::FAILURE;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderReport(array $report): void
    {
        $status = (string) ($report['status'] ?? 'unknown');
        $workflow = is_array($report['workflow'] ?? null) ? $report['workflow'] : [];
        $this->components->twoColumnDetail('status', $status);
        $this->components->twoColumnDetail('workflow', sprintf(
            '%s / %s',
            $workflow['instance_id'] ?? 'unknown-instance',
            $workflow['run_id'] ?? 'unknown-run',
        ));

        $eligibility = is_array($report['eligibility'] ?? null) ? $report['eligibility'] : [];

        foreach ($eligibility['warnings'] ?? [] as $warning) {
            if (is_array($warning)) {
                $this->components->warn(sprintf('%s: %s', $warning['rule'] ?? 'warning', $warning['message'] ?? ''));
            }
        }

        foreach ($eligibility['errors'] ?? [] as $error) {
            if (is_array($error)) {
                $this->components->error(sprintf('%s: %s', $error['rule'] ?? 'error', $error['message'] ?? ''));
            }
        }

        if (is_array($report['rows'] ?? null)) {
            foreach ($report['rows'] as $table => $count) {
                if (is_int($count) && $count > 0) {
                    $this->components->twoColumnDetail((string) $table, (string) $count);
                }
            }
        }
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
