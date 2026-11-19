<?php

declare(strict_types=1);

use App\Providers\StateMachineUpgradeServiceProvider as Host;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// The host application's own tables for the selected example. They are not
// package migrations, so a package schema rollback never reaches them.
return new class() extends Migration {
    private const SCHEMAS = [
        Host::PROVIDER => [
            'business-state-machines/schema.php',
            'provider-state-machines/schema.php',
        ],
        Host::CONFIGURED => [
            'business-state-machines/schema.php',
            'business-state-machines/provider-schema.php',
            'business-state-machines/application-schema.php',
            'configured-state-machines/schema.php',
        ],
    ];

    private const TABLES = [
        Host::PROVIDER => [
            'provider_machine_cancellations', 'provider_machine_milestones', 'provider_machine_admissions',
            'provider_machine_assessments', 'provider_machine_actors', 'provider_machine_assignments',
        ],
        Host::CONFIGURED => [
            'configured_machine_cancellations', 'configured_machine_admissions', 'configured_machine_assessments',
            'example_machine_bindings', 'example_machine_compensation_log', 'example_machine_effects',
        ],
    ];

    private const SHARED_TABLES = ['example_machine_publications', 'example_machine_decisions', 'example_machine_subjects'];

    public function up(): void
    {
        $example = Host::example() ?? throw new LogicException('Set STATE_MACHINE_EXAMPLE before migrating.');
        foreach (self::SCHEMAS[$example] as $path) {
            $schema = require base_path('state-machine-examples/' . $path);
            $schema(Schema::getFacadeRoot());
        }
    }

    public function down(): void
    {
        $example = Host::example() ?? throw new LogicException('Set STATE_MACHINE_EXAMPLE before rolling back.');
        foreach ([...self::TABLES[$example], ...self::SHARED_TABLES] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
