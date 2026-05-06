<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Workflow;

/**
 * Builds and validates the stable JSON IR used for compiled workflow
 * definitions.
 *
 * @api
 */
final class CompiledWorkflowDefinition
{
    public const SCHEMA = 'durable-workflow.v2.compiled-workflow-ir';

    public const SCHEMA_VERSION = 1;

    public const SOURCE_PHP_CLASS = 'php_class';

    public const SOURCE_SERVERLESS_WORKFLOW = 'serverless_workflow';

    public const STEP_ENTRY = 'workflow.entry';

    public const STEP_QUERY = 'workflow.query';

    public const STEP_SIGNAL = 'workflow.signal';

    public const STEP_UPDATE = 'workflow.update';

    public const STEP_STATE = 'workflow.state';

    public const STEP_ACTION = 'workflow.action';

    /**
     * @param class-string<Workflow> $workflowClass
     * @return array<string, mixed>
     */
    public static function fromWorkflowClass(
        string $workflowClass,
        ?string $workflowType = null,
        ?string $definitionVersion = null,
    ): array {
        if (! is_subclass_of($workflowClass, Workflow::class)) {
            throw new LogicException(sprintf(
                'Compiled workflow definitions require a [%s] subclass; [%s] given.',
                Workflow::class,
                $workflowClass,
            ));
        }

        $type = self::nonEmptyString($workflowType) ?? TypeRegistry::for($workflowClass);
        $sourceFingerprint = WorkflowDefinition::fingerprint($workflowClass);

        if ($sourceFingerprint === null) {
            throw new LogicException(sprintf(
                'Workflow [%s] cannot be compiled because no definition fingerprint is available.',
                $workflowClass,
            ));
        }

        $contract = WorkflowDefinition::commandContract($workflowClass);
        $version = self::nonEmptyString($definitionVersion) ?? $sourceFingerprint;
        $steps = [
            [
                'id' => self::stableStepId(self::STEP_ENTRY, $contract['entry_method']),
                'kind' => self::STEP_ENTRY,
                'name' => $contract['entry_method'],
                'method' => $contract['entry_method'],
                'mode' => $contract['entry_mode'],
                'declaring_class' => $contract['entry_declaring_class'],
            ],
        ];

        foreach (self::namedContracts($contract['queries'], $contract['query_contracts']) as $query) {
            $steps[] = self::commandStep(self::STEP_QUERY, $query);
        }

        foreach (self::namedContracts($contract['signals'], $contract['signal_contracts']) as $signal) {
            $steps[] = self::commandStep(self::STEP_SIGNAL, $signal);
        }

        foreach (self::namedContracts($contract['updates'], $contract['update_contracts']) as $update) {
            $steps[] = self::commandStep(self::STEP_UPDATE, $update);
        }

        $compiled = [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA_VERSION,
            'workflow_type' => $type,
            'definition_version' => $version,
            'definition_fingerprint' => null,
            'source' => [
                'format' => self::SOURCE_PHP_CLASS,
                'workflow_class' => $workflowClass,
                'workflow_type' => $type,
                'workflow_definition_fingerprint' => $sourceFingerprint,
            ],
            'entrypoint_step_id' => $steps[0]['id'],
            'steps' => $steps,
            'version_selection' => [
                'strategy' => 'definition_fingerprint',
                'selected_version' => $version,
                'available_versions' => [$version],
            ],
        ];

        $compiled['definition_fingerprint'] = self::fingerprint($compiled);
        self::assertValid($compiled);

        return $compiled;
    }

    /**
     * JSON Schema for the compiled workflow IR document.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://durable-workflow.github.io/schemas/v2/compiled-workflow-ir.schema.json',
            'title' => 'Durable Workflow v2 compiled workflow IR',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'schema',
                'schema_version',
                'workflow_type',
                'definition_version',
                'definition_fingerprint',
                'source',
                'entrypoint_step_id',
                'steps',
                'version_selection',
            ],
            'properties' => [
                'schema' => ['const' => self::SCHEMA],
                'schema_version' => ['const' => self::SCHEMA_VERSION],
                'workflow_type' => ['type' => 'string', 'minLength' => 1],
                'definition_version' => ['type' => 'string', 'minLength' => 1],
                'definition_fingerprint' => [
                    'type' => 'string',
                    'pattern' => '^sha256:[a-f0-9]{64}$',
                ],
                'source' => [
                    'type' => 'object',
                    'required' => ['format'],
                    'properties' => [
                        'format' => [
                            'enum' => [
                                self::SOURCE_PHP_CLASS,
                                self::SOURCE_SERVERLESS_WORKFLOW,
                            ],
                        ],
                    ],
                    'additionalProperties' => true,
                ],
                'entrypoint_step_id' => ['type' => 'string', 'minLength' => 1],
                'steps' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'kind', 'name'],
                        'properties' => [
                            'id' => ['type' => 'string', 'minLength' => 1],
                            'kind' => [
                                'enum' => [
                                    self::STEP_ENTRY,
                                    self::STEP_QUERY,
                                    self::STEP_SIGNAL,
                                    self::STEP_UPDATE,
                                    self::STEP_STATE,
                                    self::STEP_ACTION,
                                ],
                            ],
                            'name' => ['type' => 'string', 'minLength' => 1],
                            'parameters' => ['type' => 'array'],
                        ],
                        'additionalProperties' => true,
                    ],
                ],
                'version_selection' => [
                    'type' => 'object',
                    'required' => ['strategy', 'selected_version', 'available_versions'],
                    'properties' => [
                        'strategy' => ['type' => 'string', 'minLength' => 1],
                        'requested_version' => ['type' => ['string', 'null']],
                        'selected_version' => ['type' => 'string', 'minLength' => 1],
                        'available_versions' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'minLength' => 1],
                        ],
                    ],
                    'additionalProperties' => true,
                ],
            ],
        ];
    }

    public static function stableStepId(
        string $kind,
        string $identity,
        ?string $parentId = null,
        ?string $label = null,
    ): string {
        $base = sprintf(
            '%s:%s:%s',
            self::kindPrefix($kind),
            self::slug($label ?? $identity),
            substr(hash('sha256', $kind . "\n" . $identity), 0, 12),
        );

        return $parentId === null || $parentId === ''
            ? $base
            : $parentId . '/' . $base;
    }

    /**
     * @param array<string, mixed> $compiled
     */
    public static function fingerprint(array $compiled): string
    {
        unset($compiled['definition_fingerprint'], $compiled['version_selection']);

        return 'sha256:' . hash('sha256', self::canonicalJson($compiled));
    }

    /**
     * @param array<string, mixed> $compiled
     * @param array<string, mixed> $versionSelection
     * @return array<string, mixed>
     */
    public static function withVersionSelection(array $compiled, array $versionSelection): array
    {
        $compiled['version_selection'] = $versionSelection;
        self::assertValid($compiled);

        return $compiled;
    }

    /**
     * @param array<string, mixed> $compiled
     */
    public static function assertValid(array $compiled): void
    {
        foreach ([
            'schema',
            'schema_version',
            'workflow_type',
            'definition_version',
            'definition_fingerprint',
            'source',
            'entrypoint_step_id',
            'steps',
            'version_selection',
        ] as $field) {
            if (! array_key_exists($field, $compiled)) {
                throw new LogicException(sprintf('Compiled workflow IR is missing required field [%s].', $field));
            }
        }

        if ($compiled['schema'] !== self::SCHEMA || $compiled['schema_version'] !== self::SCHEMA_VERSION) {
            throw new LogicException('Compiled workflow IR uses an unsupported schema identity or version.');
        }

        foreach (['workflow_type', 'definition_version', 'definition_fingerprint', 'entrypoint_step_id'] as $field) {
            if (self::nonEmptyString($compiled[$field] ?? null) === null) {
                throw new LogicException(sprintf('Compiled workflow IR field [%s] must be a non-empty string.', $field));
            }
        }

        if (! is_array($compiled['source'])) {
            throw new LogicException('Compiled workflow IR source must be an object.');
        }

        if (! is_array($compiled['version_selection'])) {
            throw new LogicException('Compiled workflow IR version_selection must be an object.');
        }

        if (! is_array($compiled['steps']) || ! array_is_list($compiled['steps']) || $compiled['steps'] === []) {
            throw new LogicException('Compiled workflow IR steps must be a non-empty list.');
        }

        $stepIds = [];
        foreach ($compiled['steps'] as $step) {
            if (! is_array($step)) {
                throw new LogicException('Compiled workflow IR steps must be objects.');
            }

            $id = self::nonEmptyString($step['id'] ?? null);
            if ($id === null) {
                throw new LogicException('Compiled workflow IR steps must have non-empty ids.');
            }

            if (array_key_exists($id, $stepIds)) {
                throw new LogicException(sprintf(
                    'Compiled workflow IR step id [%s] is duplicated. Give indistinguishable imported steps explicit names.',
                    $id,
                ));
            }

            $stepIds[$id] = true;
        }

        if (! array_key_exists((string) $compiled['entrypoint_step_id'], $stepIds)) {
            throw new LogicException('Compiled workflow IR entrypoint_step_id must reference a compiled step.');
        }
    }

    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::sortForJson($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param list<string> $names
     * @param list<array<string, mixed>> $contracts
     * @return list<array{name: string, parameters: list<array<string, mixed>>}>
     */
    private static function namedContracts(array $names, array $contracts): array
    {
        $byName = [];

        foreach ($contracts as $contract) {
            $name = self::nonEmptyString($contract['name'] ?? null);

            if ($name === null) {
                continue;
            }

            $parameters = $contract['parameters'] ?? [];
            $byName[$name] = [
                'name' => $name,
                'parameters' => is_array($parameters) && array_is_list($parameters)
                    ? array_values($parameters)
                    : [],
            ];
        }

        sort($names);

        $normalized = [];
        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $normalized[] = $byName[$name] ?? [
                'name' => $name,
                'parameters' => [],
            ];
        }

        return $normalized;
    }

    /**
     * @param array{name: string, parameters: list<array<string, mixed>>} $contract
     * @return array<string, mixed>
     */
    private static function commandStep(string $kind, array $contract): array
    {
        return [
            'id' => self::stableStepId($kind, $contract['name']),
            'kind' => $kind,
            'name' => $contract['name'],
            'parameters' => $contract['parameters'],
        ];
    }

    private static function kindPrefix(string $kind): string
    {
        return match ($kind) {
            self::STEP_ENTRY => 'entry',
            self::STEP_QUERY => 'query',
            self::STEP_SIGNAL => 'signal',
            self::STEP_UPDATE => 'update',
            self::STEP_STATE => 'state',
            self::STEP_ACTION => 'action',
            default => self::slug($kind),
        };
    }

    private static function slug(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $value);
        $slug = is_string($slug) ? strtolower(trim($slug, '-')) : '';

        return $slug !== '' ? $slug : 'unnamed';
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private static function sortForJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::sortForJson(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = self::sortForJson($item);
        }

        return $value;
    }
}
