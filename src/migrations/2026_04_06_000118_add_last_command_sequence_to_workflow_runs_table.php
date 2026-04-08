<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->unsignedInteger('last_command_sequence')
                ->default(0)
                ->after('last_history_sequence');
        });

        DB::table('workflow_runs')
            ->select('id')
            ->orderBy('id')
            ->chunk(100, static function ($runs): void {
                foreach ($runs as $run) {
                    $sequence = 0;
                    $commands = DB::table('workflow_commands')
                        ->select(['id', 'command_sequence'])
                        ->where('workflow_run_id', $run->id)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->get();

                    foreach ($commands as $command) {
                        ++$sequence;

                        if ((int) ($command->command_sequence ?? 0) === $sequence) {
                            continue;
                        }

                        DB::table('workflow_commands')
                            ->where('id', $command->id)
                            ->update([
                                'command_sequence' => $sequence,
                            ]);
                    }

                    DB::table('workflow_runs')
                        ->where('id', $run->id)
                        ->update([
                            'last_command_sequence' => $sequence,
                        ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('workflow_runs', static function (Blueprint $table): void {
            $table->dropColumn('last_command_sequence');
        });
    }
};
