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
            $table->unsignedBigInteger('control_revision')->default(0);
            $table->text('control')->nullable();
            $table->unsignedBigInteger('compacted_through')->default(0);
        });
        Schema::create('sync_controls', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('fingerprint', 64);
            $table->text('response');
            $table->timestamp('created_at');
            $table->primary(['user_id', 'operation_id']);
        });
        Schema::create('sync_compactions', function (Blueprint $table) {
            $table->foreignId('stream_id')->constrained('sync_streams')->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('fingerprint', 64);
            $table->text('response');
            $table->timestamp('created_at');
            $table->primary(['stream_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('sync_streams')->where('control_revision', '>', 0)->orWhere('compacted_through', '>', 0)->exists()) {
            throw new RuntimeException('Authenticated controls and compaction cannot be removed after use.');
        }
        Schema::dropIfExists('sync_compactions');
        Schema::dropIfExists('sync_controls');
        Schema::table('sync_streams', fn (Blueprint $table) => $table->dropColumn(['control_revision', 'control', 'compacted_through']));
    }
};
