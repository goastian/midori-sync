<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        foreach (['title', 'description', 'url', 'host'] as $column) {
            DB::statement("CREATE INDEX CONCURRENTLY library_links_{$column}_trgm_idx ON library_links USING gin ({$column} gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        foreach (['title', 'description', 'url', 'host'] as $column) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS library_links_{$column}_trgm_idx");
        }
    }
};
