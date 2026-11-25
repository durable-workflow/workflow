<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Illuminate\Validation\ValidationException;
use RuntimeException;
use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use Workflow\UpdateValidator;
use function Workflow\V2\activity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Attributes\Type;
use function Workflow\V2\signal;
use function Workflow\V2\timer;
use Workflow\V2\Workflow;

#[Type('test-validated-update-workflow')]
#[Signal('close')]
final class TestValidatedUpdateWorkflow extends Workflow
{
    public static int $validatorCalls = 0;

    public static int $handlerCalls = 0;

    private int $capacity = 0;

    private bool $validatorTouchedState = false;

    /**
     * @var list<string>
     */
    private array $items = [];

    public function handle(int $capacity = 2): array
    {
        $this->capacity = $capacity;

        signal('close');

        return $this->items;
    }

    #[QueryMethod]
    public function cart(): array
    {
        return [
            'capacity' => $this->capacity,
            'items' => $this->items,
            'validator_touched_state' => $this->validatorTouchedState,
        ];
    }

    #[UpdateMethod('add-item')]
    public function addItem(string $item): array
    {
        self::$handlerCalls++;
        $this->items[] = $item;

        return $this->items;
    }

    #[UpdateValidator('add-item')]
    public function validateAddItem(string $item): ?bool
    {
        self::$validatorCalls++;

        // Mutations made by a validator are discarded with the replayed state.
        $this->validatorTouchedState = true;

        return match (true) {
            $item === '' => throw ValidationException::withMessages([
                'item' => ['The item is required.'],
            ]),
            $item === 'schedule-activity' => activity(TestGreetingActivity::class, $item),
            $item === 'start-timer' => timer(60),
            $item === 'refuse' => false,
            count($this->items) >= $this->capacity => throw new RuntimeException(sprintf(
                'The cart already holds %d items.',
                count($this->items),
            )),
            default => null,
        };
    }
}
