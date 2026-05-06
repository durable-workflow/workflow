<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;

/**
 * Imports Serverless Workflow SDK JSON documents into the compiled
 * workflow IR consumed by Durable Workflow v2 tooling.
 *
 * @api
 */
final class ServerlessWorkflowCompiler
{
    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function compile(array $document, ?string $definitionVersion = null): array
    {
        $workflowType = self::stringValue($document['id'] ?? null)
            ?? self::stringValue($document['name'] ?? null);

        if ($workflowType === null) {
            throw new LogicException('Serverless Workflow imports require a non-empty top-level id or name.');
        }

        $version = self::stringValue($definitionVersion)
            ?? self::stringValue($document['version'] ?? null)
            ?? 'unversioned';

        $states = self::normalizeStates($document['states'] ?? null);
        $startState = self::startStateName($document['start'] ?? null, $states);
        $stateIds = [];

        foreach ($states as $state) {
            $stateIds[$state['name']] = CompiledWorkflowDefinition::stableStepId(
                CompiledWorkflowDefinition::STEP_STATE,
                $state['name'],
            );
        }

        if (! array_key_exists($startState, $stateIds)) {
            throw new LogicException(sprintf(
                'Serverless Workflow start state [%s] does not match an imported state.',
                $startState,
            ));
        }

        usort($states, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        $steps = [];
        foreach ($states as $state) {
            $definition = $state['definition'];
            $stateId = $stateIds[$state['name']];
            $transitionName = self::transitionTarget($definition['transition'] ?? null);
            $transitionStepId = null;

            if ($transitionName !== null) {
                if (! array_key_exists($transitionName, $stateIds)) {
                    throw new LogicException(sprintf(
                        'Serverless Workflow state [%s] transitions to unknown state [%s].',
                        $state['name'],
                        $transitionName,
                    ));
                }

                $transitionStepId = $stateIds[$transitionName];
            }

            $steps[] = [
                'id' => $stateId,
                'kind' => CompiledWorkflowDefinition::STEP_STATE,
                'name' => $state['name'],
                'state_type' => self::stringValue($definition['type'] ?? null),
                'transition' => $transitionName,
                'transition_step_id' => $transitionStepId,
                'end' => (bool) ($definition['end'] ?? false),
                'source_path' => $state['source_path'],
            ];

            foreach (self::normalizeActions($definition['actions'] ?? null, $state['source_path']) as $action) {
                $identity = $action['name'] . "\n" . CompiledWorkflowDefinition::canonicalJson($action['definition']);
                $steps[] = [
                    'id' => CompiledWorkflowDefinition::stableStepId(
                        CompiledWorkflowDefinition::STEP_ACTION,
                        $identity,
                        $stateId,
                        $action['name'],
                    ),
                    'kind' => CompiledWorkflowDefinition::STEP_ACTION,
                    'name' => $action['name'],
                    'state_step_id' => $stateId,
                    'function_ref' => self::functionRef($action['definition']['functionRef'] ?? null),
                    'event_ref' => self::eventRef($action['definition']['eventRef'] ?? null),
                    'source_path' => $action['source_path'],
                ];
            }
        }

        $compiled = [
            'schema' => CompiledWorkflowDefinition::SCHEMA,
            'schema_version' => CompiledWorkflowDefinition::SCHEMA_VERSION,
            'workflow_type' => $workflowType,
            'definition_version' => $version,
            'definition_fingerprint' => null,
            'source' => [
                'format' => CompiledWorkflowDefinition::SOURCE_SERVERLESS_WORKFLOW,
                'id' => self::stringValue($document['id'] ?? null),
                'name' => self::stringValue($document['name'] ?? null),
                'version' => self::stringValue($document['version'] ?? null),
                'spec_version' => self::stringValue($document['specVersion'] ?? null),
                'document_fingerprint' => 'sha256:' . hash(
                    'sha256',
                    CompiledWorkflowDefinition::canonicalJson($document),
                ),
            ],
            'entrypoint_step_id' => $stateIds[$startState],
            'steps' => $steps,
            'version_selection' => [
                'strategy' => 'single_definition',
                'selected_version' => $version,
                'available_versions' => [$version],
            ],
        ];

        $compiled['definition_fingerprint'] = CompiledWorkflowDefinition::fingerprint($compiled);
        CompiledWorkflowDefinition::assertValid($compiled);

        return $compiled;
    }

    /**
     * @param iterable<array<string, mixed>> $documents
     * @return array<string, mixed>
     */
    public static function compileSelected(iterable $documents, ?string $requestedVersion = null): array
    {
        $compiled = [];

        foreach ($documents as $document) {
            if (! is_array($document)) {
                throw new LogicException('Serverless Workflow version sets may only contain JSON objects.');
            }

            $compiled[] = self::compile($document);
        }

        return WorkflowDefinitionVersionSelector::select($compiled, $requestedVersion);
    }

    /**
     * @return list<array{name: string, definition: array<string, mixed>, source_path: string}>
     */
    private static function normalizeStates(mixed $states): array
    {
        if (! is_array($states) || $states === []) {
            throw new LogicException('Serverless Workflow imports require a non-empty states collection.');
        }

        $normalized = [];
        $names = [];

        if (array_is_list($states)) {
            foreach ($states as $index => $state) {
                if (! is_array($state)) {
                    throw new LogicException('Serverless Workflow states must be objects.');
                }

                $name = self::stringValue($state['name'] ?? null);
                if ($name === null) {
                    throw new LogicException(sprintf(
                        'Serverless Workflow state at index [%d] is missing a non-empty name.',
                        $index,
                    ));
                }

                if (array_key_exists($name, $names)) {
                    throw new LogicException(sprintf('Serverless Workflow state [%s] is duplicated.', $name));
                }

                $names[$name] = true;
                $normalized[] = [
                    'name' => $name,
                    'definition' => $state,
                    'source_path' => 'states.' . $index,
                ];
            }

            return $normalized;
        }

        foreach ($states as $key => $state) {
            if (! is_array($state)) {
                throw new LogicException('Serverless Workflow states must be objects.');
            }

            $fallbackName = is_string($key) && $key !== '' ? $key : null;
            $name = self::stringValue($state['name'] ?? null) ?? $fallbackName;

            if ($name === null) {
                throw new LogicException('Serverless Workflow mapped states require string keys or names.');
            }

            if (array_key_exists($name, $names)) {
                throw new LogicException(sprintf('Serverless Workflow state [%s] is duplicated.', $name));
            }

            $names[$name] = true;
            $normalized[] = [
                'name' => $name,
                'definition' => ['name' => $name, ...$state],
                'source_path' => 'states.' . $name,
            ];
        }

        return $normalized;
    }

    /**
     * @param list<array{name: string, definition: array<string, mixed>, source_path: string}> $states
     */
    private static function startStateName(mixed $start, array $states): string
    {
        if (is_string($start) && trim($start) !== '') {
            return trim($start);
        }

        if (is_array($start)) {
            foreach (['stateName', 'state', 'name'] as $key) {
                $name = self::stringValue($start[$key] ?? null);

                if ($name !== null) {
                    return $name;
                }
            }
        }

        return $states[0]['name'];
    }

    /**
     * @return list<array{name: string, definition: array<string, mixed>, source_path: string}>
     */
    private static function normalizeActions(mixed $actions, string $stateSourcePath): array
    {
        if ($actions === null) {
            return [];
        }

        if (! is_array($actions) || $actions === []) {
            throw new LogicException('Serverless Workflow actions must be an object or non-empty list.');
        }

        $actionList = array_is_list($actions) ? $actions : [$actions];
        $normalized = [];

        foreach ($actionList as $index => $action) {
            if (! is_array($action)) {
                throw new LogicException('Serverless Workflow actions must be objects.');
            }

            $name = self::actionName($action);
            $normalized[] = [
                'name' => $name,
                'definition' => $action,
                'source_path' => $stateSourcePath . '.actions.' . $index,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $action
     */
    private static function actionName(array $action): string
    {
        return self::stringValue($action['name'] ?? null)
            ?? self::functionRef($action['functionRef'] ?? null)
            ?? self::eventRef($action['eventRef'] ?? null)
            ?? 'action-' . substr(hash('sha256', CompiledWorkflowDefinition::canonicalJson($action)), 0, 12);
    }

    private static function transitionTarget(mixed $transition): ?string
    {
        if (is_string($transition) && trim($transition) !== '') {
            return trim($transition);
        }

        if (! is_array($transition)) {
            return null;
        }

        foreach (['nextState', 'state', 'target'] as $key) {
            $target = self::stringValue($transition[$key] ?? null);

            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    private static function functionRef(mixed $functionRef): ?string
    {
        if (is_string($functionRef) && trim($functionRef) !== '') {
            return trim($functionRef);
        }

        if (! is_array($functionRef)) {
            return null;
        }

        foreach (['refName', 'name', 'function'] as $key) {
            $name = self::stringValue($functionRef[$key] ?? null);

            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    private static function eventRef(mixed $eventRef): ?string
    {
        if (is_string($eventRef) && trim($eventRef) !== '') {
            return trim($eventRef);
        }

        if (! is_array($eventRef)) {
            return null;
        }

        foreach (['refName', 'name', 'event'] as $key) {
            $name = self::stringValue($eventRef[$key] ?? null);

            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}
