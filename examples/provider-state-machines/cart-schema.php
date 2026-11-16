<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Optional cart/domain Order records; generic machine subjects remain purchase execution projections. */
return static function (Builder $schema): void {
    $schema->create('provider_cart_purchases', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('principal');
        $table->string('authority_version', 64);
        $table->string('assignment_sha256', 64);
        $table->text('plan');
        $table->string('plan_sha256', 64);
        $table->string('selected')
            ->nullable();
        $table->text('expiry_receipt')
            ->nullable();
        $table->string('expiry_sha256', 64)
            ->nullable();
        $table->string('order_id', 64)
            ->nullable()
            ->unique();
    });
    $schema->create('provider_cart_orders', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('purchase_key', 64)
            ->unique();
        $table->text('receipt');
        $table->string('receipt_sha256', 64);
    });
};
