<?php

declare(strict_types=1);

namespace Workflow\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Workflow\V2\Support\BundleIntegrityVerifier;
use Workflow\V2\Support\ReplayDiff;

#[AsCommand(name: 'workflow:v2:replay-verify')]
class V2ReplayVerifyCommand extends Command
{
    protected $signature = 'workflow:v2:replay-verify
        {bundle : Path to a workflow:v2:history-export bundle (JSON)}
        {--signing-key= : HMAC key for signature verification (overrides config)}
        {--skip-replay : Verify integrity only; do not replay against current code}
        {--strict-warnings : Treat structural warnings as failures}
        {--json : Emit a single machine-readable JSON report}
        {--output= : Write the JSON report to a file instead of stdout}';

    protected $description = 'Verify a Workflow v2 history bundle: integrity, structure, and replay against current code';

    private const REPORT_SCHEMA = 'durable-workflow.v2.replay-verify-report';

    private const REPORT_SCHEMA_VERSION = 1;

    public function __construct(
        private readonly Filesystem $files,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('bundle');

        if (! $this->files->exists($path)) {
            return $this->emitFailure(sprintf('Bundle file [%s] does not exist.', $path));
        }

        $contents = $this->files->get($path);

        $signingKey = $this->stringOption('signing-key');
        $integrity = BundleIntegrityVerifier::verifyJson($contents, $signingKey);

        $replayDiff = null;

        if (! (bool) $this->option('skip-replay') && $integrity['status'] !== BundleIntegrityVerifier::STATUS_FAILED) {
            $replayDiff = $this->replayDiff($contents);
        }

        $report = $this->compose($path, $integrity, $replayDiff);
        $exitCode = $this->resolveExitCode($report);

        $this->emit($report);

        return $exitCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function replayDiff(string $contents): ?array
    {
        try {
            $bundle = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($bundle)) {
            return null;
        }

        try {
            return (new ReplayDiff())->diffExport($bundle);
        } catch (Throwable $exception) {
            return [
                'schema' => ReplayDiff::REPORT_SCHEMA,
                'schema_version' => ReplayDiff::REPORT_SCHEMA_VERSION,
                'status' => ReplayDiff::STATUS_FAILED,
                'reason' => ReplayDiff::REASON_REPLAY_ERROR,
                'workflow' => null,
                'divergence' => null,
                'replay' => null,
                'error' => [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            ];
        }
    }

    /**
     * @param array<string, mixed> $integrity
     * @param array<string, mixed>|null $replayDiff
     *
     * @return array<string, mixed>
     */
    private function compose(string $bundlePath, array $integrity, ?array $replayDiff): array
    {
        $verdict = $this->verdict($integrity, $replayDiff);

        return [
            'schema' => self::REPORT_SCHEMA,
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'bundle_path' => $bundlePath,
            'verdict' => $verdict,
            'integrity' => $integrity,
            'replay_diff' => $replayDiff,
        ];
    }

    /**
     * @param array<string, mixed> $integrity
     * @param array<string, mixed>|null $replayDiff
     */
    private function verdict(array $integrity, ?array $replayDiff): string
    {
        if ($integrity['status'] === BundleIntegrityVerifier::STATUS_FAILED) {
            return 'failed';
        }

        if ($replayDiff !== null) {
            $replayStatus = $replayDiff['status'] ?? null;

            if ($replayStatus === ReplayDiff::STATUS_FAILED) {
                return 'failed';
            }

            if ($replayStatus === ReplayDiff::STATUS_DRIFTED) {
                return 'drifted';
            }
        }

        if ($integrity['status'] === BundleIntegrityVerifier::STATUS_WARNING) {
            return 'warning';
        }

        return 'ok';
    }

    /**
     * @param array<string, mixed> $report
     */
    private function resolveExitCode(array $report): int
    {
        $verdict = $report['verdict'] ?? null;

        if ($verdict === 'failed' || $verdict === 'drifted') {
            return self::FAILURE;
        }

        if ($verdict === 'warning' && (bool) $this->option('strict-warnings')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
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
        $verdict = (string) ($report['verdict'] ?? 'ok');
        $integrity = $report['integrity'] ?? [];
        $replay = $report['replay_diff'] ?? null;
        $bundleSummary = $integrity['bundle'] ?? [];

        $verdictLabel = match ($verdict) {
            'ok' => '<info>OK</info>',
            'warning' => '<comment>WARNING</comment>',
            'drifted' => '<error>DRIFTED</error>',
            'failed' => '<error>FAILED</error>',
            default => $verdict,
        };

        $this->line(sprintf('Replay verification: %s', $verdictLabel));
        $this->line(sprintf('Bundle: %s', $report['bundle_path'] ?? 'n/a'));
        $this->line(sprintf(
            'Workflow: run=%s instance=%s events=%d',
            $bundleSummary['workflow_run_id'] ?? 'n/a',
            $bundleSummary['workflow_instance_id'] ?? 'n/a',
            (int) ($bundleSummary['history_event_count'] ?? 0),
        ));

        $integrityBlock = is_array($integrity['integrity'] ?? null) ? $integrity['integrity'] : [];
        $checksumMatches = $integrityBlock['checksum_matches'] ?? null;
        $signatureVerified = $integrityBlock['signature_verified'] ?? null;

        $this->line(sprintf(
            'Integrity: status=%s checksum_match=%s signature=%s',
            $integrity['status'] ?? 'unknown',
            self::ternary($checksumMatches),
            $integrityBlock['signature_present']
                ? self::ternary($signatureVerified)
                : 'unsigned',
        ));

        $summary = is_array($integrity['summary'] ?? null) ? $integrity['summary'] : [];
        $this->line(sprintf(
            'Findings: errors=%d warnings=%d info=%d',
            (int) ($summary['errors'] ?? 0),
            (int) ($summary['warnings'] ?? 0),
            (int) ($summary['info'] ?? 0),
        ));

        $findings = is_array($integrity['findings'] ?? null) ? $integrity['findings'] : [];

        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }

            $this->line(sprintf(
                '  [%s] %s — %s%s',
                strtoupper((string) ($finding['severity'] ?? 'info')),
                (string) ($finding['rule'] ?? 'unknown'),
                (string) ($finding['message'] ?? ''),
                isset($finding['path']) && $finding['path'] !== null ? ' (' . $finding['path'] . ')' : '',
            ));
        }

        if (is_array($replay)) {
            $this->renderReplayDiff($replay);
        }
    }

    /**
     * @param array<string, mixed> $replay
     */
    private function renderReplayDiff(array $replay): void
    {
        $status = (string) ($replay['status'] ?? 'unknown');
        $reason = (string) ($replay['reason'] ?? 'unknown');

        $this->line(sprintf('Replay: status=%s reason=%s', $status, $reason));

        $divergence = $replay['divergence'] ?? null;
        if (is_array($divergence)) {
            $this->line(sprintf(
                '  Drift at workflow sequence %d: code yielded [%s], history recorded [%s]',
                (int) ($divergence['workflow_sequence'] ?? 0),
                (string) ($divergence['expected_shape'] ?? 'unknown'),
                implode(', ', is_array($divergence['recorded_event_types'] ?? null)
                    ? array_map('strval', $divergence['recorded_event_types'])
                    : []),
            ));
        }

        $error = $replay['error'] ?? null;
        if (is_array($error)) {
            $this->line(sprintf(
                '  Error: %s — %s',
                (string) ($error['class'] ?? 'Throwable'),
                (string) ($error['message'] ?? ''),
            ));
        }
    }

    private static function ternary(?bool $value): string
    {
        return match ($value) {
            true => 'yes',
            false => 'no',
            null => 'n/a',
        };
    }

    private function emitFailure(string $message): int
    {
        $report = [
            'schema' => self::REPORT_SCHEMA,
            'schema_version' => self::REPORT_SCHEMA_VERSION,
            'bundle_path' => (string) $this->argument('bundle'),
            'verdict' => 'failed',
            'integrity' => null,
            'replay_diff' => null,
            'error' => $message,
        ];

        if ((bool) $this->option('json') || $this->stringOption('output') !== null) {
            $this->emitJson($report);
        } else {
            $this->error($message);
        }

        return self::FAILURE;
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
