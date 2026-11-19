<?php

declare(strict_types=1);

namespace Tests\Fixtures\V2;

use Workflow\QueryMethod;
use Workflow\UpdateMethod;
use function Workflow\V2\await;
use Workflow\V2\Workflow;

/**
 * Replay evidence for comment-only definition edits (#1227).
 *
 * The recorded history this workflow replays was written by a build of this
 * class without any of its comments. Version 2 definition and condition
 * fingerprints ignore comments, so the run keeps its definition: the missing
 * patch marker takes the current branch, and the condition wait matches.
 */
final class TestCommentOnlyFingerprintWorkflow extends Workflow
{
    private bool $approved = false;

    private ?bool $patched = null;

    private string $stage = 'waiting-for-approval';

    public function handle(): array
    {
        // The approval arrives through the approve() update.
        await(
            fn (): bool => $this->approved, /* edited after the run started */
            conditionKey: 'comment-only.approved',
        );

        $this->stage = 'approved';

        // A change deployed after the run started. Its marker is missing from
        // history, so the recorded definition decides the branch.
        $this->patched = Workflow::patched('comment-only-fingerprint');

        $this->stage = 'completed';

        return $this->currentState();
    }

    #[UpdateMethod]
    public function approve(bool $approved = true): array
    {
        $this->approved = $approved;

        return $this->currentState();
    }

    #[QueryMethod]
    public function currentState(): array
    {
        return [
            'approved' => $this->approved,
            'patched' => $this->patched,
            'stage' => $this->stage,
        ];
    }
}
