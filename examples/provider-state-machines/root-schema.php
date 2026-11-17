<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Optional original-root business intent storage; install before selecting reservedRoots. */
return static function (Builder $schema): void {
    $schema->create('provider_machine_root_intents', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->text('identity');
        $table->string('identity_sha256', 64);
        $table->string('policy_version', 128);
    });
    $schema->create('provider_root_cancellations', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('root_key', 64)
            ->unique();
        $table->string('identity_sha256', 64);
        $table->text('request_key');
        $table->string('request_sha256', 64);
    });
};
