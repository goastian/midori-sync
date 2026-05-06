<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Consolidate the legacy `open-tabs` collection into the canonical
 * `tabs` collection.
 *
 * Background: prior to 2026-05-05 the seeder created both `tabs` and
 * `open-tabs` while the extension's `COLLECTION_INDEX` had no entry for
 * `tabs`, so writes to either collection name from the extension never
 * resolved to a real KDF index. After consolidation the canonical name
 * is `tabs`; `open-tabs` survives only as a backward-compat alias in the
 * extension that maps to the same KDF index (3).
 *
 * Strategy:
 *   1. If both `tabs` and `open-tabs` collection rows exist, move any
 *      records pointing at `open-tabs` over to `tabs` and drop the
 *      `open-tabs` row.
 *   2. If only `open-tabs` exists, rename it to `tabs`.
 *   3. If only `tabs` exists, do nothing.
 *
 * The `down()` direction is a best-effort restore that recreates the
 * `open-tabs` row but does not move any records back: alias semantics
 * make round-tripping records meaningless.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabs = DB::table('collections')->where('name', 'tabs')->first();
        $open = DB::table('collections')->where('name', 'open-tabs')->first();

        if ($tabs && $open) {
            DB::table('records')
                ->where('collection_id', $open->id)
                ->update(['collection_id' => $tabs->id]);
            DB::table('user_collections')
                ->where('collection_id', $open->id)
                ->delete();
            DB::table('collections')->where('id', $open->id)->delete();
        } elseif ($open && ! $tabs) {
            DB::table('collections')
                ->where('id', $open->id)
                ->update([
                    'name' => 'tabs',
                    'description' => 'Currently open tabs',
                ]);
        }
    }

    public function down(): void
    {
        $exists = DB::table('collections')->where('name', 'open-tabs')->exists();
        if (! $exists) {
            DB::table('collections')->insert([
                'name' => 'open-tabs',
                'description' => 'Currently open tabs (legacy)',
            ]);
        }
    }
};
