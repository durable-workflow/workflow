<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use JsonException;
use Throwable;

/**
 * Batch verifier for directories of exported history bundles.
 *
 * The result is CI/agent friendly: one top-level verdict and promotion
 * decision, with per-bundle integrity and replay-diff reports retained
 * for diagnostics.
 *
 * @api Stable v2 contract surface for replay simulation reports.
 */
final class ReplaySimulation
{
    /**
     * @return array<string, mixed>
     */
    public function simulateDirectory(
        string $directory,
        ?string $signingKey = null,
        bool $skipReplay = false,
        bool $strictWarnings = false,
    ): array {
        if (! is_dir($directory)) {
            return $this->emptyReport(
                missingBundles: [$directory],
                error: sprintf('Bundle directory [%s] does not exist.', $directory),
                skipReplay: $skipReplay,
                strictWarnings: $strictWarnings,
            );
        }

        $pattern = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.json';
        $paths = glob($pattern) ?: [];
        sort($paths, SORT_STRING);

        if ($paths === []) {
            return $this->emptyReport(
                missingBundles: [$pattern],
                error: sprintf('No history-export bundle JSON files were found in [%s].', $directory),
                skipReplay: $skipReplay,
                strictWarnings: $strictWarnings,
            );
        }

        $bundles = [];
        $verdicts = [];
        $summary = self::emptySummary();

        foreach ($paths as $path) {
            $entry = $this->simulateBundle((string) $path, $signingKey, $skipReplay, $strictWarnings);
            $verdict = self::stringValue($entry['verdict'] ?? null) ?? ReplayVerification::VERDICT_FAILED;

            $bundles[] = $entry;
            $verdicts[] = $verdict;
            $summary['total']++;
            $summary[$verdict] = ($summary[$verdict] ?? 0) + 1;
        }

        $overall = ReplayVerification::aggregateVerdicts($verdicts);
        $evidence = ReplayVerification::simulationEvidence(
            bundles: $bundles,
            missingBundles: [],
            replaySkipped: $skipReplay,
            strictWarnings: $strictWarnings,
        );

        return [
            'schema' => ReplayVerification::SIMULATION_REPORT_SCHEMA,
            'schema_version' => ReplayVerification::SIMULATION_REPORT_SCHEMA_VERSION,
            'verdict' => $overall,
            'promotion_decision' => ReplayVerification::promotionDecisionForReport($overall, $evidence),
            'evidence' => $evidence,
            'summary' => $summary,
            'bundles' => $bundles,
            'missing_bundles' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function simulateBundle(
        string $path,
        ?string $signingKey,
        bool $skipReplay,
        bool $strictWarnings,
    ): array {
        $contents = @file_get_contents($path);

        if (! is_string($contents)) {
            $verdict = ReplayVerification::VERDICT_FAILED;
            $evidence = ReplayVerification::verificationEvidence(
                integrity: null,
                replayDiff: null,
                replaySkipped: $skipReplay,
                strictWarnings: $strictWarnings,
            );

            return [
                'bundle_path' => $path,
                'verdict' => $verdict,
                'promotion_decision' => ReplayVerification::promotionDecisionForReport($verdict, $evidence),
                'evidence' => $evidence,
                'integrity' => null,
                'replay_diff' => null,
                'error' => [
                    'class' => 'RuntimeException',
                    'message' => sprintf('Bundle file [%s] could not be read.', $path),
                ],
            ];
        }

        $integrity = BundleIntegrityVerifier::verifyJson($contents, $signingKey);
        $replayDiff = null;

        if (! $skipReplay && $integrity['status'] !== BundleIntegrityVerifier::STATUS_FAILED) {
            $replayDiff = $this->replayDiff($contents);
        }

        $verdict = ReplayVerification::verdictFor($integrity, $replayDiff, $strictWarnings);
        $evidence = ReplayVerification::verificationEvidence(
            integrity: $integrity,
            replayDiff: $replayDiff,
            replaySkipped: $skipReplay,
            strictWarnings: $strictWarnings,
        );

        return [
            'bundle_path' => $path,
            'verdict' => $verdict,
            'promotion_decision' => ReplayVerification::promotionDecisionForReport($verdict, $evidence),
            'evidence' => $evidence,
            'strict_warning_failure' => $strictWarnings
                && ($integrity['status'] ?? null) === BundleIntegrityVerifier::STATUS_WARNING,
            'integrity' => $integrity,
            'replay_diff' => $replayDiff,
            'error' => null,
        ];
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
     * @param list<string> $missingBundles
     *
     * @return array<string, mixed>
     */
    private function emptyReport(
        array $missingBundles,
        string $error,
        bool $skipReplay = false,
        bool $strictWarnings = false,
    ): array {
        $verdict = ReplayVerification::VERDICT_FAILED;
        $evidence = ReplayVerification::simulationEvidence(
            bundles: [],
            missingBundles: $missingBundles,
            replaySkipped: $skipReplay,
            strictWarnings: $strictWarnings,
        );

        return [
            'schema' => ReplayVerification::SIMULATION_REPORT_SCHEMA,
            'schema_version' => ReplayVerification::SIMULATION_REPORT_SCHEMA_VERSION,
            'verdict' => $verdict,
            'promotion_decision' => ReplayVerification::promotionDecisionFor($verdict),
            'evidence' => $evidence,
            'summary' => self::emptySummary(),
            'bundles' => [],
            'missing_bundles' => $missingBundles,
            'error' => $error,
        ];
    }

    /**
     * @return array{total: int, ok: int, warning: int, drifted: int, failed: int}
     */
    private static function emptySummary(): array
    {
        return [
            'total' => 0,
            ReplayVerification::VERDICT_OK => 0,
            ReplayVerification::VERDICT_WARNING => 0,
            ReplayVerification::VERDICT_DRIFTED => 0,
            ReplayVerification::VERDICT_FAILED => 0,
        ];
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
