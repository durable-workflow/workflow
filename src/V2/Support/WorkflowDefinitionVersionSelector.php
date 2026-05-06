<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;

/**
 * Selects one compiled workflow definition from an imported version set.
 *
 * @api
 */
final class WorkflowDefinitionVersionSelector
{
    /**
     * @param iterable<array<string, mixed>> $compiledDefinitions
     * @return array<string, mixed>
     */
    public static function select(iterable $compiledDefinitions, ?string $requestedVersion = null): array
    {
        $byVersion = [];

        foreach ($compiledDefinitions as $compiled) {
            if (! is_array($compiled)) {
                throw new LogicException('Compiled workflow definition version sets may only contain IR objects.');
            }

            CompiledWorkflowDefinition::assertValid($compiled);

            $version = self::version($compiled);
            if (array_key_exists($version, $byVersion)) {
                throw new LogicException(sprintf(
                    'Compiled workflow definition version [%s] appears more than once.',
                    $version,
                ));
            }

            $byVersion[$version] = $compiled;
        }

        if ($byVersion === []) {
            throw new LogicException('Cannot select from an empty compiled workflow definition version set.');
        }

        $versions = array_keys($byVersion);
        usort($versions, self::compareVersions(...));

        $requested = self::nonEmptyString($requestedVersion);
        if ($requested !== null) {
            if (! array_key_exists($requested, $byVersion)) {
                throw new LogicException(sprintf(
                    'Compiled workflow definition version [%s] is not available. Available versions: [%s].',
                    $requested,
                    implode(', ', $versions),
                ));
            }

            return CompiledWorkflowDefinition::withVersionSelection($byVersion[$requested], [
                'strategy' => 'explicit_version',
                'requested_version' => $requested,
                'selected_version' => $requested,
                'available_versions' => $versions,
            ]);
        }

        $current = [];
        foreach ($byVersion as $version => $compiled) {
            if (($compiled['current'] ?? false) === true || (($compiled['metadata']['current'] ?? false) === true)) {
                $current[$version] = $compiled;
            }
        }

        if (count($current) > 1) {
            throw new LogicException(sprintf(
                'Compiled workflow definition version set has multiple current definitions: [%s].',
                implode(', ', array_keys($current)),
            ));
        }

        if (count($current) === 1) {
            $selectedVersion = (string) array_key_first($current);

            return CompiledWorkflowDefinition::withVersionSelection($current[$selectedVersion], [
                'strategy' => 'current_marker',
                'requested_version' => null,
                'selected_version' => $selectedVersion,
                'available_versions' => $versions,
            ]);
        }

        $selectedVersion = $versions[array_key_last($versions)];
        $strategy = self::allSemanticVersions($versions)
            ? 'highest_semantic_version'
            : 'highest_deterministic_version';

        return CompiledWorkflowDefinition::withVersionSelection($byVersion[$selectedVersion], [
            'strategy' => $strategy,
            'requested_version' => null,
            'selected_version' => $selectedVersion,
            'available_versions' => $versions,
        ]);
    }

    /**
     * @param array<string, mixed> $compiled
     */
    private static function version(array $compiled): string
    {
        $version = self::nonEmptyString($compiled['definition_version'] ?? null);

        if ($version === null) {
            throw new LogicException('Compiled workflow definition IR must carry a non-empty definition_version.');
        }

        return $version;
    }

    private static function compareVersions(string $left, string $right): int
    {
        $leftSemantic = self::isSemanticVersionish($left);
        $rightSemantic = self::isSemanticVersionish($right);

        if ($leftSemantic && $rightSemantic) {
            $comparison = version_compare($left, $right);

            return $comparison === 0
                ? strcmp($left, $right)
                : $comparison;
        }

        if ($leftSemantic !== $rightSemantic) {
            return $leftSemantic ? 1 : -1;
        }

        return strcmp($left, $right);
    }

    /**
     * @param list<string> $versions
     */
    private static function allSemanticVersions(array $versions): bool
    {
        foreach ($versions as $version) {
            if (! self::isSemanticVersionish($version)) {
                return false;
            }
        }

        return true;
    }

    private static function isSemanticVersionish(string $version): bool
    {
        return preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}
