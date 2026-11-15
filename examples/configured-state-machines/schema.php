<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Additional application tables; install the shared SQL example schemas first, only in a disposable application. */
return static function (Builder $schema): void {
    $schema->table('example_machine_subjects', static function (Blueprint $table): void {
        $table->boolean('enabled')
            ->default(true);
        $table->boolean('prevalidated')
            ->default(false);
        $table->string('active_binding_id', 64)
            ->nullable();
    });
    $schema->create('configured_machine_assessments', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('subject_id', 64);
        $table->string('request_sha256', 64);
        $table->string('data_sha256', 64);
        $table->text('facts');
        $table->text('explanation');
        $table->text('snapshot');
    });
    $schema->create('configured_machine_admissions', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('subject_id', 64);
        $table->string('request_sha256', 64);
        $table->text('decision');
    });
    $schema->create('configured_machine_cancellations', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('subject_id', 64);
        $table->string('original_run_id');
        $table->string('request_sha256', 64);
    });
};
