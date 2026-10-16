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
            $table->string('custody', 16)->default('server');
            $table->string('identity_key_id', 22)->nullable();
            $table->text('identity_bundle')->nullable();
            $table->string('registry_auth', 64)->nullable();
        });
        Schema::create('sync_key_rotations', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('fingerprint', 64);
            $table->string('target_key_id', 22);
            $table->text('source_key_ids');
            $table->string('phase', 16);
            $table->timestamps();
        });
        Schema::create('sync_history_id_migrations', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->uuid('rotation_id');
            $table->string('fingerprint', 64);
            $table->text('response');
            $table->timestamp('created_at');
            $table->primary(['user_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('sync_crypto_states')->where('custody', '<>', 'server')->exists()) {
            throw new RuntimeException('Client key custody cannot be rolled back after activation.');
        }
        Schema::dropIfExists('sync_history_id_migrations');
        Schema::dropIfExists('sync_key_rotations');
        Schema::table('sync_crypto_states', fn (Blueprint $table) => $table->dropColumn(['custody', 'identity_key_id', 'identity_bundle', 'registry_auth']));
    }
};
