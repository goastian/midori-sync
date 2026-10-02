<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_link_operation_receipts', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('fingerprint', 64);
            $table->uuid('link_id');
            $table->unsignedBigInteger('revision');
            $table->boolean('deleted');
            $table->timestamp('created_at');
            $table->primary(['user_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_link_operation_receipts');
    }
};
