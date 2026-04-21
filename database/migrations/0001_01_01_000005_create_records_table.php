<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->string('record_id');
            $table->unsignedInteger('version')->default(1);
            $table->text('payload');
            $table->timestamp('ttl')->nullable();
            $table->boolean('deleted')->default(false);
            $table->decimal('modified_at', 16, 6);
            $table->timestamps();

            $table->unique(['user_id', 'collection_id', 'record_id']);
            $table->index(['user_id', 'collection_id', 'modified_at'], 'records_delta_sync_index');
            $table->index('ttl', 'records_ttl_gc_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('records');
    }
};
