<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('sync_streams')->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('fingerprint', 64);
            $table->text('result');
            $table->timestamp('created_at');
            $table->unique(['stream_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_operations');
    }
};
