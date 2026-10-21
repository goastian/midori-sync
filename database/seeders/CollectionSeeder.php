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
            ['name' => 'browser-settings', 'description' => 'Browser preferences and settings'],
            ['name' => 'midori-tab', 'description' => 'Midori Tab widgets, themes, and shortcuts'],
            ['name' => 'midori-privacy', 'description' => 'Midori Privacy filter lists and site toggles'],
            ['name' => 'devices', 'description' => 'Connected device metadata'],
            ['name' => 'passwords', 'description' => 'Encrypted password entries'],
            ['name' => 'credit-cards', 'description' => 'Encrypted payment card entries'],
            ['name' => 'form-history', 'description' => 'Encrypted form field history'],
            ['name' => 'addresses', 'description' => 'Encrypted autofill address entries'],
            ['name' => 'workspaces', 'description' => 'Encrypted workspace names and icons'],
            ['name' => 'site-permissions', 'description' => 'Encrypted portable site blocks and exceptions'],
            ['name' => 'extensions', 'description' => 'Encrypted official extension catalog references'],
            ['name' => 'extension-settings', 'description' => 'Encrypted extension storage.sync settings'],
        ];

        DB::table('collections')->upsert($collections, ['name'], ['description']);
    }
}
