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
            $table->string('refresh_hash', 64)->nullable()->unique();
            $table->timestamp('refresh_expires_at')->nullable()->index();
            $table->timestamp('refreshed_at')->nullable();
            $table->json('refresh_identity')->nullable();
        });
        Schema::create('sync_refresh_receipts', function (Blueprint $table) {
            $table->string('token_hash', 64)->primary();
            $table->foreignUuid('session_id')->constrained('sync_sessions')->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->text('response')->nullable();
            $table->timestamp('created_at');
            $table->unique(['session_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('sync_sessions')->whereNotNull('refresh_hash')->exists()) {
            throw new RuntimeException('Revoke renewable sessions before removing their credentials and replay history.');
        }
        Schema::dropIfExists('sync_refresh_receipts');
        Schema::table('sync_sessions', function (Blueprint $table) {
            $table->dropUnique(['refresh_hash']);
            $table->dropIndex(['refresh_expires_at']);
            $table->dropColumn(['refresh_hash', 'refresh_expires_at', 'refreshed_at', 'refresh_identity']);
        });
    }
};
