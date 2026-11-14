<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Install only in the example application's disposable database, not in DW migrations. */
return static function (Builder $schema): void {
    $schema->create('example_machine_subjects', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('scope');
        $table->string('subject');
        $table->string('principal');
        $table->string('authority_version', 128);
        $table->string('state');
        $table->bigInteger('revision');
        $table->boolean('cancellation_requested')
            ->default(false);
    });
    $schema->create('example_machine_decisions', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('subject_id', 64);
        $table->string('request_sha256', 64);
        $table->string('status');
        $table->string('reason');
    });
    $schema->create('example_machine_publications', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('subject_id', 64);
        $table->text('payload');
    });
};
