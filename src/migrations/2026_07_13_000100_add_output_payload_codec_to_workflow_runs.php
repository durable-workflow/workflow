<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workflow\Support\WorkflowMigration;
use Workflow\V2\Support\ExternalPayloads;

return new class() extends WorkflowMigration {
    public function up(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            if (! Schema::hasColumn('workflow_runs', 'output_payload_codec')) {
                $table->string('output_payload_codec')
                    ->nullable()
                    ->after('output');
            }
        });

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

    private function backfillOutputPayloadCodecs(): void
    {
        $connection = DB::connection($this->getConnection());

        $connection->table('workflow_history_events')
            ->select(['workflow_run_id', 'sequence', 'payload'])
            ->where('event_type', 'WorkflowCompleted')
            ->whereNotNull('payload')
            ->orderByDesc('sequence')
            ->orderBy('workflow_run_id')
            ->chunk(500, static function ($events) use ($connection): void {
                foreach ($events as $event) {
                    $payload = self::payloadArray($event->payload ?? null);
                    $codec = $payload['payload_codec'] ?? null;

                    if (! is_string($codec) || trim($codec) === '') {
                        continue;
                    }

                    $codec = trim($codec);

                    $connection->table('workflow_runs')
                        ->where('id', $event->workflow_run_id)
                        ->whereNotNull('output')
                        ->whereNull('output_payload_codec')
                        ->update([
                            'output_payload_codec' => $codec,
                        ]);
                }
            });

        $connection->table('workflow_runs')
            ->select(['id', 'output'])
            ->whereNotNull('output')
            ->whereNull('output_payload_codec')
            ->chunkById(500, static function ($runs) use ($connection): void {
                foreach ($runs as $run) {
                    $output = $run->output ?? null;

                    if (! is_string($output)) {
                        continue;
                    }

                    try {
                        $envelope = ExternalPayloads::storedEnvelope($output);
                    } catch (\Throwable) {
                        continue;
                    }

                    $codec = $envelope['codec'] ?? null;

                    if (! is_string($codec) || $codec === '') {
                        continue;
                    }

                    $connection->table('workflow_runs')
                        ->where('id', $run->id)
                        ->whereNull('output_payload_codec')
                        ->update([
                            'output_payload_codec' => $codec,
                        ]);
                }
            }, 'id');
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
