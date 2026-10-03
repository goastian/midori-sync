<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_server_recovery', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->uuid('epoch');
            $table->text('encrypted_secret');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('sync_server_recovery')->exists()) {
            throw new RuntimeException('Server recovery secrets cannot be removed while accounts depend on them.');
        }
        Schema::dropIfExists('sync_server_recovery');
    }
};
