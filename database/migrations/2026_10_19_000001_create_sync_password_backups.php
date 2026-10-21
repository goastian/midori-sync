<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_password_backups', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->uuid('epoch');
            $table->string('registry_fingerprint', 64);
            $table->text('capsule');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_password_backups');
    }
};
