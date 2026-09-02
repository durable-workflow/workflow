<?php

declare(strict_types=1);

namespace Workflow\Traits;

use React\Promise\PromiseInterface;
use function React\Promise\resolve;
use Workflow\ContinuedWorkflow;
use Workflow\Models\StoredWorkflow;
use Workflow\WorkflowOptions;

trait Continues
{
    public static function continueAsNew(...$arguments): PromiseInterface
    {
        $context = self::$context;

        if (! $context->replaying) {
            $parentWorkflow = $context->storedWorkflow->parents()
                ->wherePivot('parent_index', '!=', StoredWorkflow::CONTINUE_PARENT_INDEX)
                ->wherePivot('parent_index', '!=', StoredWorkflow::ACTIVE_WORKFLOW_INDEX)
                ->withPivot('parent_index')
                ->first();

            $newWorkflow = self::make($context->storedWorkflow->class);

            if ($parentWorkflow) {
                $parentWorkflow->children()
                    ->attach($newWorkflow->storedWorkflow, [
                        'parent_index' => $parentWorkflow->pivot->parent_index,
                        'parent_now' => $context->now,
                    ]);

                $parentWorkflow->children()
                    ->wherePivot('parent_index', $parentWorkflow->pivot->parent_index)
                    ->detach($context->storedWorkflow);
            }

            $newWorkflow->storedWorkflow->parents()
                ->attach($context->storedWorkflow, [
                    'parent_index' => StoredWorkflow::CONTINUE_PARENT_INDEX,
                    'parent_now' => $context->now,
                ]);

            $rootWorkflow = $context->storedWorkflow->parents()
                ->wherePivot('parent_index', StoredWorkflow::ACTIVE_WORKFLOW_INDEX)->first();

            if ($rootWorkflow) {
                $rootWorkflow->children()
                    ->attach($newWorkflow->storedWorkflow, [
                        'parent_index' => StoredWorkflow::ACTIVE_WORKFLOW_INDEX,
                        'parent_now' => $context->now,
                    ]);

                $rootWorkflow->children()
                    ->wherePivot('parent_index', StoredWorkflow::ACTIVE_WORKFLOW_INDEX)
                    ->detach($context->storedWorkflow);
            } else {
                $context->storedWorkflow->children()
                    ->attach($newWorkflow->storedWorkflow, [
                        'parent_index' => StoredWorkflow::ACTIVE_WORKFLOW_INDEX,
                        'parent_now' => $context->now,
                    ]);
            }

            if (! collect($arguments)->contains(static fn ($argument): bool => $argument instanceof WorkflowOptions)) {
                $options = $context->storedWorkflow->workflowOptions();

                if ($options->connection !== null || $options->queue !== null) {
                    $arguments[] = $options;
                }
            }

            $newWorkflow->start(...$arguments);
        }

        self::$context = $context;

        return resolve(new ContinuedWorkflow());
    }
}
