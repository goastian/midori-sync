<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_crypto_states', function (Blueprint $table) {
            $table->boolean('legacy_frozen')->default(false);
        });
    }

    public function down(): void
    {
        if (DB::table('sync_crypto_states')->where('legacy_frozen', true)->exists()) {
            throw new RuntimeException('A frozen legacy migration must be resolved before removing its write barrier.');
        }
        Schema::table('sync_crypto_states', fn (Blueprint $table) => $table->dropColumn('legacy_frozen'));
    }
};
