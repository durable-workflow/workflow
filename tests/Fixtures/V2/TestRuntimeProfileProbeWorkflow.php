<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\V2\Workflow;

final class TestRuntimeProfileProbeWorkflow extends Workflow
{
    /**
     * @var list<string>
     */
    public static array $invocations = [];

    public function __construct()
    {
        self::$invocations[] = 'construct';
    }

    public function handle(): string
    {
        self::$invocations[] = 'handle';

        return 'done';
    }

    #[QueryMethod]
    public function inspect(): string
    {
        self::$invocations[] = 'query';

        return 'query';
    }

    #[UpdateMethod]
    public function mutate(): string
    {
        self::$invocations[] = 'update';

        return 'update';
    }
}
