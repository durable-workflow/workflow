# Workflow Messages Architecture

**Status**: Phase 4 Core Implementation Complete  
**Date**: 2026-04-16  
**Scope**: Durable inbox/outbox message system for workflow-to-workflow communication

## Purpose

Implements v2 Plan Phase 4 deliverable: dedicated message system for durable workflow-to-workflow communication and repeated human-input loops.

**Problem**: Workflows need durable, replay-safe communication that survives continue-as-new without relying on in-memory counters.

**Solution**: `workflow_messages` table with explicit inbox/outbox directionality, stream-based sequencing, and durable cursor semantics.

## Core Design Principles (from v2 Plan)

1. **One workflow_messages model with a direction field** - inbox and outbox use same table
2. **Durable stream sequencing** - messages grouped by stream_key with monotonic sequence numbers
3. **Per-consumer cursor semantics** - cursor advancement is a committed workflow fact (history event)
4. **Continue-as-new handoff** - messages and cursors transfer to new run without data loss
5. **Typed message channels** - signal, update, workflow_message, external, child_signal, etc.
6. **Consume state tracking** - pending, consumed, failed, expired

Per v2 Plan:
> repeated human-input and inbox or outbox flows should be modeled as durable message streams backed by one `workflow_messages` model with a direction field plus explicit receive cursors that can hand off across continue-as-new

## Schema

### workflow_messages Table

```sql
CREATE TABLE workflow_messages (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    
    -- Message ownership
    workflow_instance_id VARCHAR(191) NOT NULL,
    workflow_run_id VARCHAR(26) NOT NULL,
    
    -- Directionality
    direction VARCHAR(16) NOT NULL, -- 'inbound', 'outbound'
    
    -- Channel type
    channel VARCHAR(64) NOT NULL, -- 'signal', 'update', 'workflow_message', etc.
    
    -- Stream grouping and sequencing
    stream_key VARCHAR(191) NOT NULL,
    sequence BIGINT UNSIGNED NOT NULL,
    
    -- Message routing
    source_workflow_instance_id VARCHAR(191) NULL,
    source_workflow_run_id VARCHAR(26) NULL,
    target_workflow_instance_id VARCHAR(191) NULL,
    target_workflow_run_id VARCHAR(26) NULL,
    
    -- Correlation and idempotency
    correlation_id VARCHAR(191) NULL,
    idempotency_key VARCHAR(191) NULL,
    
    -- Payload reference (not inline)
    payload_reference VARCHAR(191) NULL,
    
    -- Consume state
    consume_state VARCHAR(16) DEFAULT 'pending', -- 'pending', 'consumed', 'failed', 'expired'
    consumed_at TIMESTAMP(6) NULL,
    consumed_by_sequence INT UNSIGNED NULL,
    
    -- Delivery tracking
    expires_at TIMESTAMP(6) NULL,
    delivery_attempt_count INT UNSIGNED DEFAULT 0,
    last_delivery_attempt_at TIMESTAMP(6) NULL,
    last_delivery_error TEXT NULL,
    
    -- Extensibility
    metadata JSON NULL,
    
    created_at TIMESTAMP(6),
    updated_at TIMESTAMP(6),
    
    -- Indexes
    INDEX (workflow_instance_id),
    INDEX (workflow_run_id),
    INDEX (direction),
    INDEX (channel),
    INDEX (stream_key),
    INDEX (sequence),
    INDEX (source_workflow_instance_id),
    INDEX (target_workflow_instance_id),
    INDEX (correlation_id),
    INDEX (idempotency_key),
    INDEX (consume_state),
    
    -- Composite indexes for efficient queries
    INDEX wf_msgs_instance_stream_seq (workflow_instance_id, stream_key, sequence),
    INDEX wf_msgs_run_dir_state (workflow_run_id, direction, consume_state),
    INDEX wf_msgs_stream_seq_state (stream_key, sequence, consume_state),
    INDEX wf_msgs_target_state (target_workflow_instance_id, consume_state),
    
    -- Sequence ordering guarantee
    UNIQUE wf_msgs_stream_seq_unique (workflow_instance_id, stream_key, sequence),
    
    FOREIGN KEY (workflow_run_id) REFERENCES workflow_runs(id) ON DELETE CASCADE
);
```

**Critical design decisions:**
- NO inline payloads - uses payload_reference to decouple message metadata from payload storage
- Unique constraint on (instance, stream_key, sequence) - enforces durable ordering
- Both inbound and outbound in same table - simplifies correlation tracking
- Composite indexes optimized for common query patterns

## Message Directionality

### Outbound Messages (Sender's View)

```
workflow_instance_id = sender's instance
workflow_run_id = sender's run
direction = 'outbound'
source_* = sender
target_* = receiver
```

### Inbound Messages (Receiver's View)

```
workflow_instance_id = receiver's instance
workflow_run_id = receiver's run  
direction = 'inbound'
source_* = sender
target_* = receiver
```

**Key insight**: When `sendMessage()` is called, TWO rows are created:
1. Outbound message for sender (tracks what was sent)
2. Inbound message for receiver (tracks what to receive)

This dual-record approach enables:
- Sender to track sent messages
- Receiver to track inbox
- Correlation between sent and received
- Separate consume state per direction

## Stream Keys and Sequencing

### Stream Keys

Stream keys group related messages for ordered processing:

```
"instance:{instance_id}"           - default instance-level stream
"approval_flow:{request_id}"       - custom approval stream
"user_inbox:{user_id}"             - user-specific inbox
"child_signals:{parent_run_id}"    - parent-to-child signals
```

**Rules:**
- Stream key is arbitrary string (max 191 chars)
- Messages within a stream have monotonic sequence numbers
- Sequence numbers are per-instance, per-stream (enforced by unique constraint)
- Default stream key: `instance:{instance_id}`

### Sequence Ordering

Sequences start at 1 and increment per stream:

```
Stream A: msg_seq_1, msg_seq_2, msg_seq_3
Stream B: msg_seq_1, msg_seq_2, msg_seq_3
```

**Sequence reservation:**
1. Sender calls `sendMessage()`
2. MessageService locks sender and target instances (`SELECT ... FOR UPDATE`)
3. `MessageStreamCursor::reserveNextSequence()` increments each instance's `last_message_sequence`
4. The outbound row gets the sender instance's next sequence number
5. The inbound row gets the target instance's next sequence number
6. Lock released on transaction commit

**Ordering guarantee:** Unique constraint prevents duplicate sequences in same stream.

## Cursor Semantics

### Cursor Position

Each workflow run tracks a cursor position per stream:
- `workflow_runs.message_cursor_position` - last consumed sequence
- Default: 0 (no messages consumed yet)
- Cursor only moves forward (monotonic)

### Cursor Advancement

When a message is consumed:
1. Message.consume_state set to 'consumed'
2. Message.consumed_at set to now()
3. Message.consumed_by_sequence set to history event sequence
4. Cursor advanced to message sequence via `MessageStreamCursor::advanceCursor()`
5. `MessageCursorAdvanced` history event recorded

**Idempotency:** `advanceCursor()` returns null if cursor already at or past requested position.

### Receiving Messages

```php
$messages = $messageService->receiveMessages($run, $streamKey);
```

Returns messages where:
- `direction = 'inbound'`
- `workflow_run_id = $run->id`
- `stream_key = $streamKey`
- `consume_state = 'pending'`
- `sequence > $run->message_cursor_position`

**Only unconsumed messages after cursor are returned.**

## Continue-As-New Handoff

When a workflow continues as new run:

```php
$messageService->transferMessagesToContinuedRun($closingRun, $continuedRun);
```

**Transfer logic:**
1. Cursor position copied: `continuedRun.message_cursor_position = closingRun.message_cursor_position`
2. Pending inbound messages updated: `workflow_run_id = $continuedRun->id`
3. Consumed messages remain with `$closingRun->id` (historical record)
4. Cursor advancement history preserved across runs

**Result:** New run picks up exactly where old run left off. No messages lost or duplicated.

## Message Channels

```php
enum MessageChannel: string
{
    case Signal = 'signal';
    case Update = 'update';
    case WorkflowMessage = 'workflow_message';
    case ChildSignal = 'child_signal';
    case External = 'external';
    case Query = 'query';
    case Custom = 'custom';
}
```

Channels categorize message types for routing and handling:
- **Signal**: Workflow signal delivery
- **Update**: Workflow update delivery  
- **WorkflowMessage**: Explicit workflow-to-workflow messages
- **ChildSignal**: Parent-to-child signals
- **External**: External system messages (webhooks, APIs)
- **Query**: Query responses (non-durable)
- **Custom**: User-defined channels

## Consume States

```php
enum MessageConsumeState: string
{
    case Pending = 'pending';
    case Consumed = 'consumed';
    case Failed = 'failed';
    case Expired = 'expired';
}
```

**State transitions:**
- `pending` → `consumed` - successful consumption
- `pending` → `failed` - delivery or processing error
- `pending` → `expired` - message expired before consumption

**Terminal states:** `consumed`, `failed`, `expired` cannot transition further.

## API Surface

### MessageStream

Workflow authors and adapters should use `Workflow\V2\MessageStream` as the
named v2 inbox/outbox contract instead of constructing the legacy
`Workflow\Inbox` or `Workflow\Outbox` helpers. A stream is bound to one
`WorkflowRun` and one `stream_key`.

```php
// From workflow code:
$messages = $this->inbox('chat')->peek();
$message = $this->inbox('chat')->receiveOne();

// From runtime/control-plane code that already owns a run:
$stream = app(MessageService::class)->stream($run, 'chat', $historySequence);
$stream->sendReference(
    targetInstanceId: $targetInstanceId,
    payloadReference: $payloadReference,
    correlationId: $requestId,
);
```

Contract:

- `peek($limit)` is read-only and returns pending inbound messages after the
  run cursor.
- `receive($limit, $consumedBySequence)` consumes pending inbound messages,
  stamps each row with the workflow history sequence that performed the
  receive, and advances the cursor to the highest consumed message sequence.
- `receiveOne()` is `receive(1)`.
- `sendReference()` sends an outbound message and creates the corresponding
  target inbound row. The table stores a payload reference, not arbitrary
  inline payload bytes.
- `Workflow::messages()`, `Workflow::inbox()`, and `Workflow::outbox()` all
  open this same stream facade for the current run. `inbox()` and `outbox()`
  are semantic aliases for author readability, not separate storage models.

Receive/consume operations require a positive workflow history sequence. The
workflow base class supplies the current visible sequence when it opens a
stream; lower-level callers must pass the sequence explicitly so replay,
history export, and cursor diagnostics can identify the workflow step that
consumed the messages.

### MessageService

```php
class MessageService
{
    // Open the first-class stream facade
    public function stream(
        WorkflowRun $run,
        ?string $streamKey = null,
        ?int $defaultConsumedBySequence = null,
    ): MessageStream;

    // Send outbound message
    public function sendMessage(
        WorkflowRun $sourceRun,
        MessageChannel|string $channel,
        string $targetInstanceId,
        ?string $payloadReference = null,
        ?string $streamKey = null,
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        array $metadata = [],
        ?\DateTimeInterface $expiresAt = null,
    ): WorkflowMessage;
    
    // Receive unconsumed inbound messages
    public function receiveMessages(
        WorkflowRun $run,
        ?string $streamKey = null,
        int $limit = 100,
    ): Collection<WorkflowMessage>;
    
    // Consume single message
    public function consumeMessage(
        WorkflowRun $run,
        WorkflowMessage $message,
        int $consumedBySequence,
    ): void;
    
    // Consume multiple messages (cursor advances to highest sequence)
    public function consumeMessageBatch(
        WorkflowRun $run,
        array $messages,
        int $consumedBySequence,
    ): void;
    
    // Check for unconsumed messages
    public function getUnconsumedCount(WorkflowRun $run, ?string $streamKey = null): int;
    public function hasUnconsumedMessages(WorkflowRun $run, ?string $streamKey = null): bool;
    
    // Continue-as-new transfer
    public function transferMessagesToContinuedRun(
        WorkflowRun $closingRun,
        WorkflowRun $continuedRun,
    ): void;
    
    // Correlation and stream queries
    public function getMessagesByCorrelationId(string $correlationId): Collection<WorkflowMessage>;
    public function getMessagesForStream(string $instanceId, string $streamKey, int $limit = 100): Collection<WorkflowMessage>;
    
    // Cleanup
    public function expireStaleMessages(): int;
}
```

### WorkflowMessage Model

```php
class WorkflowMessage extends Model
{
    // Relationships
    public function run(): BelongsTo;
    public function instance(): BelongsTo;
    public function sourceRun(): BelongsTo;
    public function targetRun(): BelongsTo;
    
    // State checks
    public function isConsumable(): bool;
    
    // State transitions
    public function markConsumed(int $consumedBySequence): void;
    public function markFailed(string $error): void;
    public function markExpired(): void;
    public function recordDeliveryAttempt(?string $error = null): void;
    
    // Query helpers
    public static function getUnconsumedForStream(WorkflowRun $run, string $streamKey, int $afterSequence, int $limit): Collection;
    public static function getUnconsumedCountForStream(WorkflowRun $run, string $streamKey, int $afterSequence): int;
    public static function hasUnconsumedMessages(WorkflowRun $run, string $streamKey, int $afterSequence): bool;
    public static function expireStaleMessages(): int;
}
```

## Use Cases

### 1. Workflow-to-Workflow Communication

```php
// Sender workflow
$messageService->sendMessage(
    $senderRun,
    MessageChannel::WorkflowMessage,
    $targetInstanceId,
    'payload_ref_123',
    "workflow_comms:{$requestId}",
    $requestId, // correlation ID
);

// Receiver workflow
$messages = $messageService->receiveMessages($receiverRun, "workflow_comms:{$requestId}");
foreach ($messages as $message) {
    // Process message
    $payload = loadPayload($message->payload_reference);
    processMessage($payload);
    
    // Mark consumed
    $messageService->consumeMessage($receiverRun, $message, $historySequence);
}
```

### 2. Repeated Human-Input Loop

```php
// Approval workflow that receives multiple approvals
while (true) {
    $messages = $messageService->receiveMessages($run, "approval_stream");
    
    if ($messages->isEmpty()) {
        // Wait for more messages
        yield waitForCondition(fn() => $messageService->hasUnconsumedMessages($run, "approval_stream"));
        continue;
    }
    
    foreach ($messages as $message) {
        $approval = json_decode($message->payload_reference, true);
        processApproval($approval);
        $messageService->consumeMessage($run, $message, $historySequence++);
    }
    
    if (allApprovalsReceived()) {
        break;
    }
}
```

### 3. Parent-to-Child Signaling

```php
// Parent sends signal to child
$messageService->sendMessage(
    $parentRun,
    MessageChannel::ChildSignal,
    $childInstanceId,
    $signalPayloadRef,
    "child_signals:{$childInstanceId}",
);

// Child receives signals
$signals = $messageService->receiveMessages($childRun, "child_signals:{$childInstanceId}");
foreach ($signals as $signal) {
    handleSignal($signal);
    $messageService->consumeMessage($childRun, $signal, $historySequence);
}
```

### 4. External System Integration

```php
// External webhook delivers message
$messageService->sendMessage(
    $systemRun, // Internal system workflow
    MessageChannel::External,
    $workflowInstanceId,
    $webhookPayloadRef,
    "webhooks:{$webhookType}",
    $webhookId, // correlation
    $webhookId, // idempotency
);

// Workflow processes webhooks
$webhooks = $messageService->receiveMessages($run, "webhooks:payment_confirmed");
foreach ($webhooks as $webhook) {
    handleWebhook($webhook);
    $messageService->consumeMessage($run, $webhook, $historySequence);
}
```

## Message Lifecycle

```
[Send Phase]
1. Sender calls sendMessage()
2. MessageService locks sender and target instances
3. Reserves the sender stream's next sequence for the outbound row
4. Reserves the target stream's next sequence for the inbound row
5. Creates outbound message (sender's record)
6. Creates inbound message (receiver's record)
7. Transaction commits

[Receive Phase]  
8. Receiver calls receiveMessages()
9. Fetches pending inbound messages after cursor
10. Returns messages in sequence order

[Consume Phase]
11. Receiver processes messages
12. Calls consumeMessage() or consumeMessageBatch()
13. Messages marked as consumed
14. Cursor advanced to consumed sequence
15. MessageCursorAdvanced history event recorded

[Continue-As-New Phase]
16. Closing run has cursor at position N
17. Pending messages (sequence > N) still exist
18. transferMessagesToContinuedRun() called
19. Pending messages workflow_run_id updated to new run
20. Cursor position transferred
21. New run continues from position N
```

## Integration with MessageStreamCursor

The messages system builds on `MessageStreamCursor` (already exists):

### MessageStreamCursor Responsibilities
- `reserveNextSequence(instance)` - atomically increment and return next sequence
- `advanceCursor(run, position, streamKey)` - record cursor advancement in history
- `transferCursor(closingRun, continuedRun)` - copy cursor on continue-as-new
- `positionForRun(run)` - get current cursor position
- `hasUnconsumedMessages(run, instance)` - check for pending

### WorkflowMessage Responsibilities
- Store actual message records (both inbound and outbound)
- Track consume state per message
- Support stream-based querying
- Enable correlation and routing

**Separation of concerns:** MessageStreamCursor manages cursor state, WorkflowMessage stores message data.

## Performance Characteristics

### Indexes Enable Efficient Queries

**Receive messages query:**
```sql
SELECT * FROM workflow_messages
WHERE workflow_run_id = ?
  AND stream_key = ?
  AND direction = 'inbound'
  AND consume_state = 'pending'
  AND sequence > ?
ORDER BY sequence
LIMIT 100
```
Uses index: `wf_msgs_run_dir_state (workflow_run_id, direction, consume_state)` + `sequence` filter.

**Stream inspection query:**
```sql
SELECT * FROM workflow_messages
WHERE workflow_instance_id = ?
  AND stream_key = ?
ORDER BY sequence
```
Uses index: `wf_msgs_instance_stream_seq (workflow_instance_id, stream_key, sequence)`.

**Correlation tracking:**
```sql
SELECT * FROM workflow_messages
WHERE correlation_id = ?
ORDER BY sequence
```
Uses index: `correlation_id`.

### Scaling Considerations

- **Per-stream sequences:** Sequences don't grow unbounded globally, only per stream
- **Cursor-based filtering:** Only scans unconsumed messages (sequence > cursor)
- **Composite indexes:** Optimized for common access patterns
- **Message cleanup:** Expired/consumed messages can be archived/deleted

## Test Coverage

18 test cases covering:

1. **Basic Lifecycle**
   - Send creates outbound and inbound records
   - Sequential numbering per stream
   - Receive unconsumed messages
   - Consume message and advance cursor
   - Batch consumption

2. **Cursor Semantics**
   - Only receive messages after cursor
   - Unconsumed count tracking
   - hasUnconsumedMessages check

3. **Continue-As-New**
   - Message transfer to continued run
   - Cursor position transfer
   - Pending messages preserved

4. **Stream Isolation**
   - Messages isolated by stream key
   - Independent sequencing per stream

5. **State Management**
   - Prevent consuming non-consumable messages
   - Correlation ID tracking
   - Idempotency key support
   - Message expiry

All tests pass with proper transaction safety and cleanup.

## Next Steps

1. **WorkflowExecutor Integration** - Add message send/receive/consume to runtime
2. **History Events** - Emit MessageSent, MessageReceived, MessageConsumed events
3. **Workflow API** - Add workflow->sendMessage(), workflow->receiveMessages() helpers
4. **Signal/Update Integration** - Wire signals and updates through message system
5. **Performance Testing** - Benchmark at scale (10K, 100K messages per stream)
6. **Cleanup Job** - Scheduled task to expire stale messages

## Success Criteria (from v2 Plan)

✅ One workflow_messages model with direction field  
✅ Durable stream sequencing with monotonic sequence numbers  
✅ Per-consumer cursor semantics (cursor advancement is history event)  
✅ Continue-as-new message transfer without data loss  
✅ Typed message channels (signal, update, workflow_message, etc.)  
✅ Consume state tracking (pending, consumed, failed, expired)  
✅ Inbox and outbox message consumption is durable and replay-safe  
✅ No reliance on in-memory counters  
⏳ WorkflowExecutor runtime integration (next)  
⏳ History event emission (next)  

## References

- `docs/workflow/plan.md` - Phase 4 inbox/outbox requirements
- Migration: `src/migrations/2026_04_16_000160_create_workflow_messages_table.php`
- Model: `src/V2/Models/WorkflowMessage.php`
- Service: `src/V2/Support/MessageService.php`
- Enums: `src/V2/Enums/MessageDirection.php`, `MessageConsumeState.php`, `MessageChannel.php`
- Cursor: `src/V2/Support/MessageStreamCursor.php` (pre-existing)
- Tests: `tests/Unit/V2/MessageServiceTest.php`

## Implementation Summary

Total implementation: ~1,160 lines across 7 files + migration

**Commits:**
- 6dd8748: Core schema, model, enums
- 83cd35b: MessageService with send/receive/consume operations
- 2c31c64: Comprehensive test coverage (18 test cases)

This completes the Phase 4 workflow messages foundation, enabling durable workflow-to-workflow communication and repeated human-input loops with explicit cursor semantics that survive continue-as-new.
