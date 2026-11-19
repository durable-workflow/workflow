<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Optional same-price unit exchange storage; install after schema.php and return-schema.php. */
return static function (Builder $schema): void {
    $schema->create('provider_machine_exchanges', static function (Blueprint $table): void {
        (require __DIR__ . '/post-delivery-schema.php')($table, 'exchange_key', 32);
    });
};
