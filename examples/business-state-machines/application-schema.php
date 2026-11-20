<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Additional application-owned data for the native workflow example. */
return static function (Builder $schema): void {
    $schema->table('example_machine_subjects', static function (Blueprint $table): void {
        $table->string('parent_subject_id', 64)->nullable();
        $table->string('profile');
        $table->string('profile_version');
        $table->string('native_instance_id');
        $table->string('original_native_run_id')->nullable();
    });
    $schema->create('example_machine_assessments', static function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('subject_id', 64);
        $table->string('request_sha256', 64);
        $table->text('snapshot');
    });
    $schema->create('example_machine_bindings', static function (Blueprint $table): void {
        $table->string('id', 64)->primary();
        $table->string('subject_id', 64);
        $table->text('reservation');
        $table->text('admission');
        $table->boolean('business_outcome_verified')->default(false);
    });
};
