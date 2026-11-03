<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\now;
use function Workflow\V2\upsertMemo;
use function Workflow\V2\upsertSearchAttributes;
use Workflow\V2\Workflow;

#[Type('test-redrive-visibility-workflow')]
final class TestRedriveVisibilityWorkflow extends Workflow
{
    private ?string $timeAfterMemo = null;

    private ?string $timeAfterSearchAttributes = null;

    /**
     * @return array{result: string, time_after_memo: string, time_after_search_attributes: string}
     */
    public function handle(string $name): array
    {
        upsertMemo([
            'stage' => 'greeting',
            'customer' => $name,
        ]);
        $this->timeAfterMemo = now()
            ->toIso8601String();
        upsertSearchAttributes([
            'Stage' => 'greeting',
        ]);
        $this->timeAfterSearchAttributes = now()
            ->toIso8601String();
        $greeting = activity(TestRedriveFirstActivity::class, $name);

        return [
            'result' => activity(TestRedriveSecondActivity::class, $greeting),
            'time_after_memo' => $this->timeAfterMemo,
            'time_after_search_attributes' => $this->timeAfterSearchAttributes,
        ];
    }

    /**
     * @return array{time_after_memo: string|null, time_after_search_attributes: string|null}
     */
    #[QueryMethod]
    public function replayTimes(): array
    {
        return [
            'time_after_memo' => $this->timeAfterMemo,
            'time_after_search_attributes' => $this->timeAfterSearchAttributes,
        ];
    }
}
