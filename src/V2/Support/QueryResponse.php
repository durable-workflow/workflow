<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

use LogicException;
use Workflow\V2\Exceptions\InvalidQueryArgumentsException;
use Workflow\V2\Exceptions\WorkflowExecutionUnavailableException;
use Workflow\V2\WorkflowStub;

final class QueryResponse
{
    /**
     * @param array<int|string, mixed> $arguments
     * @return array{
     *     status: int,
     *     payload: array<string, mixed>
     * }
     */
    public static function execute(
        WorkflowStub $workflow,
        string $query,
        array $arguments,
        string $targetScope,
    ): array {
        $queryName = $workflow->resolveQueryTarget($query)['name'] ?? $query;

        try {
            $result = $workflow->queryWithArguments($query, $arguments);
        } catch (InvalidQueryArgumentsException $exception) {
            return [
                'status' => 422,
                'payload' => [
                    'query_name' => $exception->queryName(),
                    'workflow_id' => $workflow->id(),
                    'run_id' => $workflow->runId(),
                    'target_scope' => $targetScope,
                    'message' => $exception->getMessage(),
                    'validation_errors' => $exception->validationErrors(),
                ],
            ];
        } catch (WorkflowExecutionUnavailableException $exception) {
            return [
                'status' => 409,
                'payload' => [
                    'query_name' => $exception->targetName(),
                    'workflow_id' => $workflow->id(),
                    'run_id' => $workflow->runId(),
                    'target_scope' => $targetScope,
                    'blocked_reason' => $exception->blockedReason(),
                    'message' => $exception->getMessage(),
                ],
            ];
        } catch (LogicException $exception) {
            return [
                'status' => 409,
                'payload' => [
                    'query_name' => $queryName,
                    'workflow_id' => $workflow->id(),
                    'run_id' => $workflow->runId(),
                    'target_scope' => $targetScope,
                    'message' => $exception->getMessage(),
                ],
            ];
        }

        return [
            'status' => 200,
            'payload' => [
                'query_name' => $queryName,
                'workflow_id' => $workflow->id(),
                'run_id' => $workflow->runId(),
                'target_scope' => $targetScope,
                'result' => $result,
            ],
        ];
    }
}
