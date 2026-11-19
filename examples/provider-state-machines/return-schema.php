<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Optional customer unit-return storage; install after schema.php before requesting a unit return. */
return static function (Builder $schema): void {
    $schema->create('provider_machine_returns', static function (Blueprint $table): void {
        (require __DIR__ . '/post-delivery-schema.php')($table, 'return_key', 48);
    });
};
