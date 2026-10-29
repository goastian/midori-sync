<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_account_recovery', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->uuid('epoch');
            $table->string('registry_fingerprint', 64);
            $table->text('encrypted_capsule');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_account_recovery');
    }
};
