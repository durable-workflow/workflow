<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Create workflow_search_attributes table for typed indexed metadata.
     *
     * Phase 1 foundational table separating indexed (searchable) from memos (non-indexed).
     * Supports efficient filtering/sorting in Waterline visibility queries.
     *
     * Type system:
     * - string: variable-length text (max 2048 chars)
     * - keyword: exact-match text (max 255 chars, indexed)
     * - int: 64-bit signed integer
     * - float: double precision
     * - bool: boolean
     * - datetime: timestamp with microseconds
     */
    public function up(): void
    {
        Schema::create('workflow_search_attributes', static function (Blueprint $table): void {
            $table->id();

            // Foreign keys
            $table->string('workflow_run_id', 26)
                ->index();
            $table->string('workflow_instance_id', 191)
                ->index();

            // Attribute identity
            $table->string('key', 191)
                ->comment('Attribute name (e.g., "customer_id", "priority", "region")');
            $table->string('type', 16)
                ->comment('Type: string, keyword, int, float, bool, datetime');

            // Typed value columns (only one populated per row based on type)
            $table->text('value_string')
                ->nullable()
                ->comment('For type=string (max 2048 chars enforced at app level)');
            $table->string('value_keyword', 255)
                ->nullable()
                ->index()
                ->comment('For type=keyword (exact match, indexed)');
            $table->bigInteger('value_int')
                ->nullable()
                ->index()
                ->comment('For type=int');
            $table->double('value_float')
                ->nullable()
                ->index()
                ->comment('For type=float');
            $table->boolean('value_bool')
                ->nullable()
                ->index()
                ->comment('For type=bool');
            $table->timestamp('value_datetime', 6)
                ->nullable()
                ->index()
                ->comment('For type=datetime (microsecond precision)');

            // Metadata
            $table->unsignedInteger('upserted_at_sequence')
                ->comment('History sequence when this attribute was last upserted');
            $table->boolean('inherited_from_parent')
                ->default(false)
                ->comment('True if inherited via continue-as-new');

            $table->timestamps(6);

            // Composite unique: one attribute per key per run
            $table->unique(['workflow_run_id', 'key'], 'workflow_search_attrs_run_key_unique');

            // Index for visibility queries (namespace + attribute filtering)
            $table->index(['workflow_instance_id', 'key', 'type'], 'workflow_search_attrs_instance_key_type');

            // Index for value-based filtering by type
            $table->index(['key', 'value_keyword'], 'workflow_search_attrs_key_keyword');
            $table->index(['key', 'value_int'], 'workflow_search_attrs_key_int');
            $table->index(['key', 'value_float'], 'workflow_search_attrs_key_float');
            $table->index(['key', 'value_bool'], 'workflow_search_attrs_key_bool');
            $table->index(['key', 'value_datetime'], 'workflow_search_attrs_key_datetime');

            $table->foreign('workflow_run_id')
                ->references('id')
                ->on('workflow_runs')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_search_attributes');
    }
};
