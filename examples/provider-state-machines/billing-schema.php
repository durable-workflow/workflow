<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** Application tables only. Install business-state-machines/schema.php in the disposable app first. */
return static function (Builder $schema): void {
    $schema->create('provider_billing_subscriptions', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('principal');
        $table->string('authority_version', 64);
        $table->bigInteger('future_cutoff')
            ->nullable();
    });
    $schema->create('provider_billing_periods', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('subscription_id', 64);
        $table->string('principal');
        $table->string('authority_version', 64);
        $table->text('assignment');
        $table->string('assignment_sha256', 64);
        $table->string('native_run')
            ->nullable();
        $table->text('admission_receipt')
            ->nullable();
        $table->string('admission_sha256', 64)
            ->nullable();
        $table->bigInteger('confirmed_at')
            ->nullable();
        $table->string('effect_id')
            ->nullable();
    });
    $schema->create('provider_billing_assessments', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('period_id', 64);
        $table->string('request_sha256', 64);
        $table->text('payload');
        $table->string('payload_sha256', 64);
    });
    $schema->create('provider_billing_admissions', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('period_id', 64);
        $table->string('request_sha256', 64);
        $table->text('decision');
    });
    $schema->create('provider_billing_future_cancellations', static function (Blueprint $table): void {
        $table->string('id', 64)
            ->primary();
        $table->string('subscription_id', 64);
        $table->string('request_sha256', 64);
        $table->bigInteger('cutoff');
    });
};
