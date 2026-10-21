<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('collections')->upsert([
            ['name' => 'site-permissions', 'description' => 'Encrypted portable site blocks and exceptions'],
        ], ['name'], ['description']);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $ids = DB::table('collections')->where('name', 'site-permissions')->lockForUpdate()->pluck('id');
            foreach (['records', 'sync_streams', 'user_collections'] as $table) {
                if (DB::table($table)->whereIn('collection_id', $ids)->exists()) {
                    throw new RuntimeException('Site permissions collection cannot be removed after use.');
                }
            }
            DB::table('collections')->whereIn('id', $ids)->delete();
        });
    }
};
