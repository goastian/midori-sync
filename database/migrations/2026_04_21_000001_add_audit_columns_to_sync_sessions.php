<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_sessions', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('token_hash');
            $table->string('user_agent', 512)->nullable()->after('ip_address');
            $table->timestamp('last_used_at')->nullable()->after('user_agent');
        });
    }

    public function down(): void
    {
        Schema::table('sync_sessions', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent', 'last_used_at']);
        });
    }
};
