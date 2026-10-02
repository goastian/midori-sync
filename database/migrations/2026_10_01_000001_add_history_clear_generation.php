<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_streams', function (Blueprint $table) {
            $table->string('reset_kind', 32)->nullable();
            $table->unsignedBigInteger('reset_at_ms')->nullable();
        });

        Schema::create('sync_history_clears', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('sync_streams')->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->uuid('previous_generation');
            $table->uuid('generation');
            $table->unsignedBigInteger('clear_before_ms');
            $table->unsignedInteger('deleted_records');
            $table->timestampTz('created_at', 6);
            $table->unique(['stream_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('sync_history_clears')->exists()) {
            throw new RuntimeException('History clear state cannot be removed after use; a compatible migration is required.');
        }
        Schema::dropIfExists('sync_history_clears');
        Schema::table('sync_streams', function (Blueprint $table) {
            $table->dropColumn(['reset_kind', 'reset_at_ms']);
        });
    }
};
