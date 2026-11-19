<?php

declare(strict_types=1);

namespace Workflow\V2\StateMachine\Rules;

use Workflow\V2\StateMachine\Definition\RuleReference;

/**
 * Pure application rule. Neither method may read live state, dispatch work or wait.
 * Collect facts through recorded activities; retain this implementation for its pinned version.
 *
 * @experimental Part of the business state-machine API, which may change in a minor release.
 * See docs/api-stability.md#state-machines.
 */
interface ConditionRule
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function assertParameters(array $parameters): void;

    public function evaluate(RuleReference $rule, RecordedFacts $facts): RuleEvaluation;
}
