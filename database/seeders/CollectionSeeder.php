<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CollectionSeeder extends Seeder
{
    public function run(): void
    {
        $collections = [
            ['name' => 'bookmarks', 'description' => 'Browser bookmarks and folders'],
            ['name' => 'history', 'description' => 'Browsing history'],
            ['name' => 'tabs', 'description' => 'Currently open tabs'],
            ['name' => 'passwords', 'description' => 'Encrypted password entries'],
            ['name' => 'open-tabs', 'description' => 'Currently open tabs (legacy)'],
            ['name' => 'browser-settings', 'description' => 'Browser preferences and settings'],
            ['name' => 'midori-tab', 'description' => 'Midori Tab widgets, themes, and shortcuts'],
            ['name' => 'midori-privacy', 'description' => 'Midori Privacy filter lists and site toggles'],
            ['name' => 'devices', 'description' => 'Connected device metadata'],
        ];

        DB::table('collections')->upsert($collections, ['name'], ['description']);
    }
}
