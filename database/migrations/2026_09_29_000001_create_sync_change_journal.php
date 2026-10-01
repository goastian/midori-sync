<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->uuid('generation');
            $table->unsignedBigInteger('sequence')->default(0);
            $table->unique(['user_id', 'collection_id']);
        });

        Schema::create('sync_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('sync_streams')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->text('record');
            $table->timestamp('created_at');
            $table->unique(['stream_id', 'sequence']);
        });

        Schema::create('sync_device_cursors', function (Blueprint $table) {
            $table->foreignId('stream_id')->constrained('sync_streams')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->timestamp('acknowledged_at');
            $table->primary(['stream_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_device_cursors');
        Schema::dropIfExists('sync_changes');
        Schema::dropIfExists('sync_streams');
    }
};
