<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_native_oidc_exchanges', function (Blueprint $table) {
            $table->string('token_hash', 64)->primary();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_native_oidc_exchanges');
    }
};
