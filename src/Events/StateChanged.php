<?php

declare(strict_types=1);

namespace Workflow\Events;

use Illuminate\Queue\SerializesModels;
use Workflow\States\State;

class StateChanged
{
    use SerializesModels;

    public ?State $initialState;

    public ?State $finalState;

    public $model;

    public ?string $field;

    /**
     * @param  string|State|null  $initialState
     * @param  string|State|null  $finalState
     * @param  \Illuminate\Database\Eloquent\Model  $model
     */
    public function __construct(?State $initialState, ?State $finalState, $model, string $field)
    {
        $this->initialState = $initialState;
        $this->finalState = $finalState;
        $this->model = $model;
        $this->field = $field;
    }
}
