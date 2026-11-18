<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Optional same-price unit exchange storage; install after schema.php and return-schema.php. */
return static function (Builder $schema): void {
    $schema->create('provider_machine_exchanges', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('root_key', 64)
            ->index();
        $table->string('exchange_key', 32);
        $table->text('assignment');
        $table->string('assignment_sha256', 64);
        $table->string('native_run')
            ->nullable()
            ->unique();
        $table->text('plan')
            ->nullable();
        $table->string('plan_sha256', 64)
            ->nullable();
        $table->text('outcome')
            ->nullable();
        $table->string('outcome_sha256', 64)
            ->nullable();
    });
};
