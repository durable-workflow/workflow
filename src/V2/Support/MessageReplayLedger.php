<?php

declare(strict_types=1);

namespace Workflow\V2\Support;

/**
 * What one execution of workflow code has already matched against the message
 * table. Workflow code runs again on every replay, so a send or receive it
 * already made is matched to the row it wrote or consumed instead of acting a
 * second time (#1010).
 *
 * @internal
 */
final class MessageReplayLedger
{
    /**
     * @var array<int|string, true>
     */
    private array $claimedOutbound = [];

    /**
     * @var array<string, int>
     */
    private array $receiveOffsets = [];

    public function isClaimed(int|string $messageId): bool
    {
        return isset($this->claimedOutbound[$messageId]);
    }

    /**
     * @return list<int|string>
     */
    public function claimedOutboundIds(): array
    {
        return array_keys($this->claimedOutbound);
    }

    public function claim(int|string $messageId): void
    {
        $this->claimedOutbound[$messageId] = true;
    }

    public function receiveOffset(string $streamKey, int $consumedBySequence): int
    {
        return $this->receiveOffsets[$streamKey . "\0" . $consumedBySequence] ?? 0;
    }

    public function advanceReceive(string $streamKey, int $consumedBySequence, int $count): void
    {
        $key = $streamKey . "\0" . $consumedBySequence;
        $this->receiveOffsets[$key] = ($this->receiveOffsets[$key] ?? 0) + $count;
    }
}
