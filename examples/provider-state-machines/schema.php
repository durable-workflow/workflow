<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Application schema only. Install business-state-machines/schema.php first in a disposable database. */
return static function (Builder $schema): void {
    $schema->create('provider_machine_assignments', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->text('terms');
        $table->string('terms_sha256', 64);
        $table->string('root_run')
            ->nullable();
        $table->text('stock_refusal')
            ->nullable();
        $table->text('stock_plan')
            ->nullable();
    });
    $schema->create('provider_machine_actors', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('root_key', 64);
        $table->string('role');
        $table->string('native_run')
            ->unique();
        $table->text('scope');
    });
    $schema->create('provider_machine_assessments', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('actor_id', 64);
        $table->string('request_sha256', 64);
        $table->text('payload');
        $table->string('payload_sha256', 64);
    });
    $schema->create('provider_machine_admissions', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('actor_id', 64);
        $table->string('request_sha256', 64);
        $table->text('decision');
    });
    $schema->create('provider_machine_milestones', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('actor_id', 64);
        $table->string('child_run');
        $table->text('payload');
        $table->string('payload_sha256', 64);
    });
    $schema->create('provider_machine_cancellations', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('root_key', 64);
        $table->string('root_run');
        $table->string('request_sha256', 64);
        // The automatic finalizer names the original request when it calls finish().
        $table->string('request_key')
            ->nullable();
        $table->timestamp('requested_at', 6)
            ->nullable();
    });
};
