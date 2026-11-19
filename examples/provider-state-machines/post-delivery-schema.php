<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;

/** Shared columns of an immutable post-delivery process record (unit return or exchange). */
return static function (Blueprint $table, string $keyColumn, int $keyLength): void {
    $table->string('id', 64)
        ->primary();
    $table->string('root_key', 64)
        ->index();
    $table->string($keyColumn, $keyLength);
    $table->text('assignment');
    $table->string('assignment_sha256', 64);
    $table->string('native_run')
        ->nullable()
        ->unique();
    foreach (['plan', 'outcome'] as $record) {
        $table->text($record)
            ->nullable();
        $table->string($record . '_sha256', 64)
            ->nullable();
    }
};
