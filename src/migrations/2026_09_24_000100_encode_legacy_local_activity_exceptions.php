<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Serializers\Avro;
use Workflow\Serializers\CodecDecodeException;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Support\ExternalPayloads;
use Workflow\Support\WorkflowMigration;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        if (Schema::hasTable('activity_executions')) {
            DB::table('activity_executions')
                ->where('payload_codec', 'avro')
                ->whereNotNull('exception')
                ->select(['id', 'activity_options', 'exception'])
                ->orderBy('id')
                ->chunkById(250, function ($executions): void {
                    foreach ($executions as $execution) {
                        $options = is_string($execution->activity_options)
                            ? json_decode($execution->activity_options, true)
                            : $execution->activity_options;
                        if (! is_array($options)
                            || ($options['execution_mode'] ?? null) !== 'local'
                            || ! $this->isLegacyText($execution->exception)) {
                            continue;
                        }

                        DB::table('activity_executions')
                            ->where('id', $execution->id)
                            ->update(['exception' => Avro::serialize($execution->exception)]);
                    }
                }, 'id');
        }

        if (! Schema::hasTable('workflow_history_events')) {
            return;
        }

        DB::table('workflow_history_events')
            ->whereIn('event_type', [
                HistoryEventType::ActivityFailed->value,
                HistoryEventType::ActivityTimedOut->value,
                HistoryEventType::ActivityCancelled->value,
            ])
            ->select(['id', 'payload'])
            ->orderBy('id')
            ->chunkById(250, function ($events): void {
                foreach ($events as $event) {
                    $payload = is_string($event->payload)
                        ? json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR)
                        : $event->payload;
                    if (! is_array($payload) || ! is_array($payload['activity'] ?? null)) {
                        continue;
                    }

                    $activity = $payload['activity'];
                    if (($activity['local_activity'] ?? null) !== true
                        || ($activity['payload_codec'] ?? null) !== 'avro'
                        || ! $this->isLegacyText($activity['exception'] ?? null)) {
                        continue;
                    }

                    $payload['activity']['exception'] = Avro::serialize($activity['exception']);
                    DB::table('workflow_history_events')
                        ->where('id', $event->id)
                        ->update(['payload' => json_encode(
                            $payload,
                            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
                        )]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // Keep converted durable payloads valid on rollback.
    }

    private function isLegacyText(mixed $value): bool
    {
        if (! is_string($value) || $value === '' || ExternalPayloads::isStoredReference($value)) {
            return false;
        }

        try {
            Avro::unserialize($value);

            return false;
        } catch (CodecDecodeException) {
            return true;
        }
    }
};
