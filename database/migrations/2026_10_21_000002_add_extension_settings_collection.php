<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('collections')->upsert([
            ['name' => 'extension-settings', 'description' => 'Encrypted extension storage.sync settings'],
        ], ['name'], ['description']);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $ids = DB::table('collections')->where('name', 'extension-settings')->lockForUpdate()->pluck('id');
            foreach (['records', 'sync_streams', 'user_collections'] as $table) {
                if (DB::table($table)->whereIn('collection_id', $ids)->exists()) {
                    throw new RuntimeException('Extension settings collection cannot be removed after use.');
                }
            }
            DB::table('collections')->whereIn('id', $ids)->delete();
        });
    }
};
