<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * Shared vocabulary for offline replay verification reports.
 *
 * These constants mirror the platform replay-verification contract
 * published by the control plane. Keeping the mapping local lets CLI
 * commands and CI helpers emit promotion decisions without depending on
 * the server package.
 *
 * @api Stable v2 contract surface for replay verification reports.
 */
final class ReplayVerification
{
    public const VERIFICATION_REPORT_SCHEMA = 'durable-workflow.v2.replay-verification.report';

    public const VERIFICATION_REPORT_SCHEMA_VERSION = 1;

    public const SIMULATION_REPORT_SCHEMA = 'durable-workflow.v2.replay-simulation.report';

    public const SIMULATION_REPORT_SCHEMA_VERSION = 1;

    public const VERDICT_OK = 'ok';

    public const VERDICT_WARNING = 'warning';

    public const VERDICT_DRIFTED = 'drifted';

    public const VERDICT_FAILED = 'failed';

    public const PROMOTION_SAFE_TO_PROMOTE = 'safe_to_promote';

    public const PROMOTION_REVIEW_BEFORE_PROMOTE = 'review_before_promote';

    public const PROMOTION_BLOCK_UNTIL_COMPATIBLE = 'block_until_compatible';

    public const PROMOTION_BLOCK_AND_INVESTIGATE = 'block_and_investigate';

    /**
     * @param array<string, mixed>|null $integrity
     * @param array<string, mixed>|null $replayDiff
     */
    public static function verdictFor(
        ?array $integrity,
        ?array $replayDiff,
        bool $strictWarnings = false,
    ): string {
        if ($integrity === null) {
            return self::VERDICT_FAILED;
        }

        $integrityStatus = $integrity['status'] ?? null;

        if ($integrityStatus === BundleIntegrityVerifier::STATUS_FAILED) {
            return self::VERDICT_FAILED;
        }

        if ($strictWarnings && $integrityStatus === BundleIntegrityVerifier::STATUS_WARNING) {
            return self::VERDICT_FAILED;
        }

        if ($replayDiff !== null) {
            $replayStatus = $replayDiff['status'] ?? null;

            if ($replayStatus === ReplayDiff::STATUS_FAILED) {
                return self::VERDICT_FAILED;
            }

            if ($replayStatus === ReplayDiff::STATUS_DRIFTED) {
                return self::VERDICT_DRIFTED;
            }
        }

        if ($integrityStatus === BundleIntegrityVerifier::STATUS_WARNING) {
            return self::VERDICT_WARNING;
        }

        return self::VERDICT_OK;
    }

    public static function promotionDecisionFor(string $verdict): string
    {
        return match ($verdict) {
            self::VERDICT_OK => self::PROMOTION_SAFE_TO_PROMOTE,
            self::VERDICT_WARNING => self::PROMOTION_REVIEW_BEFORE_PROMOTE,
            self::VERDICT_DRIFTED => self::PROMOTION_BLOCK_UNTIL_COMPATIBLE,
            self::VERDICT_FAILED => self::PROMOTION_BLOCK_AND_INVESTIGATE,
            default => self::PROMOTION_BLOCK_AND_INVESTIGATE,
        };
    }

    /**
     * Resolve the promotion decision carried by a concrete report.
     *
     * A clean integrity-only report is useful evidence, but it is not enough
     * to declare replay-safe promotion. Keep the verdict honest (`ok` means
     * the checks that ran passed) while making the report's recommendation
     * match the evidence block.
     *
     * @param array<string, mixed> $evidence
     */
    public static function promotionDecisionForReport(string $verdict, array $evidence = []): string
    {
        $decision = self::promotionDecisionFor($verdict);

        if (
            $decision === self::PROMOTION_SAFE_TO_PROMOTE
            && ($evidence['replay_skipped'] ?? null) === true
        ) {
            return self::PROMOTION_REVIEW_BEFORE_PROMOTE;
        }

        return $decision;
    }

    /**
     * @param array<string, mixed>|null $integrity
     * @param array<string, mixed>|null $replayDiff
     *
     * @return array{
     *     integrity_checked: bool,
     *     integrity_status: ?string,
     *     integrity_finding_count: int,
     *     replay_checked: bool,
     *     replay_status: ?string,
     *     replay_skipped: bool,
     *     strict_warnings: bool
     * }
     */
    public static function verificationEvidence(
        ?array $integrity,
        ?array $replayDiff,
        bool $replaySkipped,
        bool $strictWarnings,
    ): array {
        $findings = is_array($integrity['findings'] ?? null)
            ? $integrity['findings']
            : [];

        return [
            'integrity_checked' => $integrity !== null,
            'integrity_status' => self::stringValue($integrity['status'] ?? null),
            'integrity_finding_count' => count($findings),
            'replay_checked' => $replayDiff !== null,
            'replay_status' => self::stringValue($replayDiff['status'] ?? null),
            'replay_skipped' => $replaySkipped,
            'strict_warnings' => $strictWarnings,
        ];
    }

    /**
     * @param list<array<string, mixed>> $bundles
     * @param list<string> $missingBundles
     *
     * @return array{
     *     bundle_count: int,
     *     missing_bundle_count: int,
     *     integrity_checked_count: int,
     *     replay_checked_count: int,
     *     replay_skipped: bool,
     *     strict_warnings: bool
     * }
     */
    public static function simulationEvidence(
        array $bundles,
        array $missingBundles,
        bool $replaySkipped,
        bool $strictWarnings,
    ): array {
        $integrityChecked = 0;
        $replayChecked = 0;

        foreach ($bundles as $bundle) {
            $evidence = is_array($bundle['evidence'] ?? null)
                ? $bundle['evidence']
                : [];

            if (($evidence['integrity_checked'] ?? null) === true) {
                $integrityChecked++;
            }

            if (($evidence['replay_checked'] ?? null) === true) {
                $replayChecked++;
            }
        }

        return [
            'bundle_count' => count($bundles),
            'missing_bundle_count' => count($missingBundles),
            'integrity_checked_count' => $integrityChecked,
            'replay_checked_count' => $replayChecked,
            'replay_skipped' => $replaySkipped,
            'strict_warnings' => $strictWarnings,
        ];
    }

    /**
     * Reduce per-bundle verdicts with the platform strictest-verdict-wins rule.
     *
     * @param list<string> $verdicts
     */
    public static function aggregateVerdicts(array $verdicts): string
    {
        if ($verdicts === []) {
            return self::VERDICT_FAILED;
        }

        $rank = [
            self::VERDICT_OK => 0,
            self::VERDICT_WARNING => 1,
            self::VERDICT_DRIFTED => 2,
            self::VERDICT_FAILED => 3,
        ];

        $worst = self::VERDICT_OK;

        foreach ($verdicts as $verdict) {
            $candidate = array_key_exists($verdict, $rank) ? $verdict : self::VERDICT_FAILED;

            if ($rank[$candidate] > $rank[$worst]) {
                $worst = $candidate;
            }
        }

        return $worst;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
