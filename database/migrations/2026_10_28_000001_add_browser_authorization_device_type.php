<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_browser_authorizations', function (Blueprint $table) {
            $table->string('device_type', 16)->default('desktop');
        });
    }

    public function down(): void
    {
        Schema::table('sync_browser_authorizations', function (Blueprint $table) {
            $table->dropColumn('device_type');
        });
    }
};
