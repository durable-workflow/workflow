<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\V2\Compensation\Definition\PortableDescriptor;
use Workflow\V2\Workflow;

final class TestPortableDescriptorReferencesWorkflow extends Workflow
{
    /**
     * @param array<string, int> $facts
     * @return array{canonical: string, originalKeys: list<int|string>}
     */
    public function handle(array $facts): array
    {
        $descriptor = [
            'facts' => &$facts,
        ];
        $canonical = PortableDescriptor::encode($descriptor);

        return [
            'canonical' => $canonical,
            'originalKeys' => array_keys($facts),
        ];
    }
}
