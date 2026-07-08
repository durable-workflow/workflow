<?php

declare(strict_types=1);

namespace Workflow\V2\Conformance;

use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

#[Type(self::TYPE_KEY)]
final class SearchAttributesConformanceWorkflow extends Workflow
{
    public const TYPE_KEY = 'workflow-v2-search-attributes-conformance';

    /**
     * @param array<string, scalar|list<string>|null> $upsertAttributes
     * @return array<string, mixed>
     */
    public function handle(array $upsertAttributes): array
    {
        Workflow::upsertSearchAttributes($upsertAttributes);

        return [
            'status' => 'completed',
            'workflow_id' => $this->workflowId(),
            'run_id' => $this->runId(),
            'upserted_search_attributes' => $upsertAttributes,
        ];
    }
}
