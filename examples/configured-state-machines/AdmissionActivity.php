<?php

declare(strict_types=1);

namespace DurableWorkflow\Examples\ConfiguredStateMachines;

use Workflow\V2\Activity;
use Workflow\V2\Attributes\Type;

#[Type('example.configured.admission.v1')]
final class AdmissionActivity extends Activity
{
    public function handle(array $request): array
    {
        return app(AssessmentStore::class)->admit($this->run, $request);
    }
}
