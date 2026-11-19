<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ProviderStateMachines;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.provider.commit.v1')]
final class CommitActivity extends Activity
{
    public function handle(array $request): array
    {
        return app(AssessmentStore::class)->commit($this->run, $request);
    }
}
