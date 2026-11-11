<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Closure;
use Workflow\UpdateMethod;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Workflow;

/**
 * Runs a test hook in the task that completes the run, so a test can accept
 * an update while that task is running (#890).
 */
#[Type('test-update-before-close-workflow')]
final class TestUpdateBeforeCloseWorkflow extends Workflow
{
    public static ?Closure $beforeReturn = null;

    /**
     * @var list<string>
     */
    private array $notes = [];

    /**
     * @return list<string>
     */
    public function handle(): array
    {
        activity(TestGreetingActivity::class, 'close');

        if (self::$beforeReturn instanceof Closure) {
            $hook = self::$beforeReturn;
            self::$beforeReturn = null;
            $hook($this->run->workflow_instance_id);
        }

        return $this->notes;
    }

    #[UpdateMethod]
    public function note(string $note): string
    {
        $this->notes[] = $note;

        return $note;
    }
}
