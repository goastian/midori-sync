<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('collections')->upsert([
            ['name' => 'form-history', 'description' => 'Encrypted form field history'],
            ['name' => 'addresses', 'description' => 'Encrypted autofill address entries'],
        ], ['name'], ['description']);
    }

    public function down(): void
    {
        $ids = DB::table('collections')->whereIn('name', ['form-history', 'addresses'])->pluck('id');
        if (DB::table('records')->whereIn('collection_id', $ids)->exists() ||
            DB::table('sync_streams')->whereIn('collection_id', $ids)->exists()) {
            throw new RuntimeException('Form-history and address collections cannot be removed after use.');
        }
        DB::table('collections')->whereIn('id', $ids)->delete();
    }
};
