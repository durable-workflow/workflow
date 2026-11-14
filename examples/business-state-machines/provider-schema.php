<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return static function (Builder $schema): void {
    $schema->create('example_machine_effects', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('subject_id', 64);
        $table->string('owner_sha256', 64);
        $table->string('action');
        $table->string('status');
    });
};
