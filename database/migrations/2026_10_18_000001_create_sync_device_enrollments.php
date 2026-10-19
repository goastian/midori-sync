<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_device_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained('sync_sessions')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('public_key', 128);
            $table->string('device_name', 100)->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('approver_public_key', 128)->nullable();
            $table->text('payload')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_device_enrollments');
    }
};
