<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;

use function Workflow\V2\await;
use function Workflow\V2\uuid4;
use function Workflow\V2\uuid7;
use Workflow\V2\Workflow;

#[Type('test-uuid-workflow')]
#[Signal('finish')]
final class TestUuidWorkflow extends Workflow
{
    /**
     * @var array{uuid4: list<string>, uuid7: list<string>}
     */
    private array $ids = [
        'uuid4' => [],
        'uuid7' => [],
    ];

    public function handle(): array
    {
        $this->ids = [
            'uuid4' => [uuid4(), uuid4()],
            'uuid7' => [uuid7(), uuid7()],
        ];

        await('finish');

        return $this->ids;
    }

    #[QueryMethod]
    public function ids(): array
    {
        return $this->ids;
    }
}
