<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_sessions', function (Blueprint $table) {
            $table->unsignedSmallInteger('protocol_version')->default(1);
        });
        Schema::create('sync_crypto_states', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->uuid('epoch');
            $table->unsignedInteger('revision')->default(0);
            $table->boolean('native_enabled')->default(false);
            $table->string('active_key_id', 22)->nullable();
        });
        Schema::create('sync_native_keys', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key_id', 22);
            $table->text('encrypted_bundle');
            $table->timestamps();
            $table->primary(['user_id', 'key_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('sync_crypto_states')->where('native_enabled', true)->exists() || DB::table('sync_native_keys')->exists()) {
            throw new RuntimeException('Native crypto state cannot be removed after activation; a compatible migration is required.');
        }
        Schema::dropIfExists('sync_native_keys');
        Schema::dropIfExists('sync_crypto_states');
        Schema::table('sync_sessions', fn (Blueprint $table) => $table->dropColumn('protocol_version'));
    }
};
