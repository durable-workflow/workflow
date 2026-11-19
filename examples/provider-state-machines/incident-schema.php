<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Optional transit-incident remedy storage; install after schema.php and return-schema.php. */
return static function (Builder $schema): void {
    $schema->create('provider_machine_incidents', static function (Blueprint $table): void {
        (require __DIR__ . '/post-delivery-schema.php')($table, 'incident', 16);
        $table->text('remedy')
            ->nullable();
        $table->string('remedy_sha256', 64)
            ->nullable();
    });
};
