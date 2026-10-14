<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Workflows\HttpWorkflow;
use Illuminate\Http\Request;
use Workflow\V2\WorkflowStub;

final class QualificationController
{
    public function start(Request $request)
    {
        $input = $request->validate(['instance_id' => ['required', 'string', 'max:80'],
            'mode' => ['required', 'in:fast,timer,signal,cancel,continue,fault']]);
        $identity = $request->attributes->get('qualification_identity');
        $workflowClass = $input['mode'] === 'fault' ? \App\Workflows\FaultWorkflow::class : HttpWorkflow::class;
        $stub = WorkflowStub::make($workflowClass, $input['instance_id'], $identity['namespace']);
        $stub->start($identity['user'], $input['mode']);

        return response()->json(['instance_id' => $input['instance_id'], 'run_id' => $stub->runId(), 'status' => $stub->status()], 202);
    }

    public function status(Request $request, string $instance)
    {
        $stub = $this->load($request, $instance);

        return response()->json(['instance_id' => $instance, 'run_id' => $stub->runId(),
            'status' => $stub->status(), 'output' => $stub->output()]);
    }

    public function query(Request $request, string $instance)
    {
        return response()->json(['snapshot' => $this->load($request, $instance)->query('snapshot')]);
    }

    public function signal(Request $request, string $instance)
    {
        $identity = $request->attributes->get('qualification_identity');
        $command = $this->load($request, $instance)->signal('finish', 'released-'.$identity['user']);

        return response()->json(['accepted' => $command->accepted(), 'outcome' => $command->outcome()]);
    }

    public function cancel(Request $request, string $instance)
    {
        $command = $this->load($request, $instance)->cancel('qualification user cancellation');

        return response()->json(['accepted' => $command->accepted(), 'outcome' => $command->outcome()]);
    }

    private function load(Request $request, string $instance): WorkflowStub
    {
        $identity = $request->attributes->get('qualification_identity');

        return WorkflowStub::loadSelection($instance, $request->query('run_id'), $identity['namespace']);
    }
}
