<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a partial index on `records` covering only active rows
 * (deleted = false) for the (user_id, collection_id, modified_at) tuple
 * used by the delta-sync hot path.
 *
 * Rationale: most reads filter by `deleted = false` and order/range over
 * `modified_at`. A partial index keeps tombstones out of the b-tree,
 * stays smaller than the existing `records_delta_sync_index`, and is
 * cheaper to maintain on write-heavy workloads.
 *
 * Database support:
 *   - PostgreSQL: native partial index.
 *   - SQLite >= 3.8.0: native partial index.
 *   - Other drivers (e.g. MySQL): falls back to a regular composite
 *     index that includes `deleted` to keep the migration portable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS records_active_delta_index '
                . 'ON records (user_id, collection_id, modified_at) '
                . 'WHERE deleted = '
                . ($driver === 'pgsql' ? 'false' : '0')
            );
            return;
        }

        Schema::table('records', function (Blueprint $table) {
            $table->index(
                ['user_id', 'collection_id', 'deleted', 'modified_at'],
                'records_active_delta_index',
            );
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS records_active_delta_index');
            return;
        }

        Schema::table('records', function (Blueprint $table) {
            $table->dropIndex('records_active_delta_index');
        });
    }
};
