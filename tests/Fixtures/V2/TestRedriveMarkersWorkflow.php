<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Support\Facades\Cache;
use Workflow\QueryMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\getVersion;
use function Workflow\V2\now;
use function Workflow\V2\sideEffect;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

#[Type('test-redrive-markers-workflow')]
final class TestRedriveMarkersWorkflow extends Workflow
{
    private ?string $timeAfterSideEffect = null;

    private ?string $timeAfterVersion = null;

    /**
     * @return array{result: string, token: string, version: int, time_after_side_effect: string, time_after_version: string}
     */
    public function handle(string $name): array
    {
        $token = sideEffect(static function (): string {
            Cache::increment('test:redrive:side-effect-calls');

            return bin2hex(random_bytes(8));
        });
        $this->timeAfterSideEffect = now()
            ->toIso8601String();
        $version = getVersion('redrive-markers', WorkflowStub::DEFAULT_VERSION, 1);
        $this->timeAfterVersion = now()
            ->toIso8601String();
        $greeting = activity(TestRedriveFirstActivity::class, $name);

        return [
            'result' => activity(TestRedriveSecondActivity::class, $greeting),
            'token' => $token,
            'version' => $version,
            'time_after_side_effect' => $this->timeAfterSideEffect,
            'time_after_version' => $this->timeAfterVersion,
        ];
    }

    /**
     * @return array{time_after_side_effect: string|null, time_after_version: string|null}
     */
    #[QueryMethod]
    public function replayTimes(): array
    {
        return [
            'time_after_side_effect' => $this->timeAfterSideEffect,
            'time_after_version' => $this->timeAfterVersion,
        ];
    }
}
