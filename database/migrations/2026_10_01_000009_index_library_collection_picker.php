<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX library_collections_user_lower_name_id_idx ON library_collections (user_id, (lower(name)) text_pattern_ops, id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS library_collections_user_lower_name_id_idx');
    }
};
