<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Support\ExternalPayloads;

return new class() extends WorkflowMigration {
    /**
     * The column is added and committed before the backfill. Each batch of
     * runs commits on its own, so live traffic never waits on a lock held
     * for the whole backfill. A re-run skips runs that already have a codec.
     */
    public $withinTransaction = false;

    public int $backfillBatchSize = 250;

    public function up(): void
    {
        if (! Schema::hasColumn('workflow_runs', 'output_payload_codec')) {
            $this->withSchemaLockBudget(static function (): void {
                Schema::table('workflow_runs', static function (Blueprint $table): void {
                    $table->string('output_payload_codec')
                        ->nullable()
                        ->after('output');
                });
            });
        }

        $this->backfillOutputPayloadCodecs();
    }

    public function down(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            if (Schema::hasColumn('workflow_runs', 'output_payload_codec')) {
                $table->dropColumn('output_payload_codec');
            }
        });
    }

    /**
     * Walk runs by primary key. The newest WorkflowCompleted event with a
     * codec wins; otherwise a self-describing stored output supplies it.
     */
    private function backfillOutputPayloadCodecs(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->table('workflow_runs')
            ->select(['id', 'output'])
            ->whereNotNull('output')
            ->whereNull('output_payload_codec')
            ->chunkById($this->backfillBatchSize, static function ($runs) use ($connection): void {
                $historyCodecs = self::historyCodecs($connection, $runs->pluck('id')->all());

                $connection->transaction(static function () use ($connection, $runs, $historyCodecs): void {
                    foreach ($runs as $run) {
                        $codec = $historyCodecs[$run->id] ?? self::storedOutputCodec($run->output ?? null);

                        if ($codec === null) {
                            continue;
                        }

                        $connection->table('workflow_runs')
                            ->where('id', $run->id)
                            ->whereNotNull('output')
                            ->whereNull('output_payload_codec')
                            ->update([
                                'output_payload_codec' => $codec,
                            ]);
                    }
                });
            }, 'id');
    }

    /**
     * @param list<string> $runIds
     * @return array<string, string>
     */
    private static function historyCodecs(Connection $connection, array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        $codecs = [];

        $events = $connection->table('workflow_history_events')
            ->select(['workflow_run_id', 'sequence', 'payload'])
            ->whereIn('workflow_run_id', $runIds)
            ->where('event_type', 'WorkflowCompleted')
            ->whereNotNull('payload')
            ->orderByDesc('sequence')
            ->get();

        foreach ($events as $event) {
            if (isset($codecs[$event->workflow_run_id])) {
                continue;
            }

            $payload = self::payloadArray($event->payload ?? null);
            $codec = $payload['payload_codec'] ?? null;

            if (! is_string($codec) || trim($codec) === '') {
                continue;
            }

            $codecs[$event->workflow_run_id] = trim($codec);
        }

        return $codecs;
    }

    private static function storedOutputCodec(mixed $output): ?string
    {
        if (! is_string($output)) {
            return null;
        }

        try {
            $envelope = ExternalPayloads::storedEnvelope($output);
        } catch (\Throwable) {
            return null;
        }

        $codec = $envelope['codec'] ?? null;

        return is_string($codec) && $codec !== '' ? $codec : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function payloadArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_object($payload)) {
            return (array) $payload;
        }

        if (! is_string($payload) || $payload === '') {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
};
