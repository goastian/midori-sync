<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_crypto_states', fn (Blueprint $table) => $table->unsignedSmallInteger('registry_auth_version')->default(1));
    }

    public function down(): void
    {
        if (DB::table('sync_crypto_states')->where('registry_auth_version', '>', 1)->exists()) {
            throw new RuntimeException('Revision-authenticated key registries cannot be downgraded.');
        }
        Schema::table('sync_crypto_states', fn (Blueprint $table) => $table->dropColumn('registry_auth_version'));
    }
};
