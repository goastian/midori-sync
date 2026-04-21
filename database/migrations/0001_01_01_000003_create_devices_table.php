<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id');
            $table->string('name');
            $table->enum('type', ['desktop', 'mobile', 'tablet'])->default('desktop');
            $table->string('os')->nullable();
            $table->string('browser_version')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_id']);
            $table->index('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
