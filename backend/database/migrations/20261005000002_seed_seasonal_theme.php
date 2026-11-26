<?php

declare(strict_types=1);

use Siro\Core\Database;

return new class {
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');
        $themes = [
            [
                'key' => 'noir',
                'name' => 'Noir',
                'version' => '1.0.0',
                'schema_version' => 1,
                'status' => 'active',
                'token_schema_json' => json_encode(['colors' => ['ink' => '#EDEAE2', 'muted' => '#A8A294', 'paper' => '#16130F', 'surface' => '#201B15', 'accent' => '#D08A5A', 'accent_dark' => '#B96F42']]),
                'allowed_section_types_json' => json_encode(['campaign_hero','collection_directory','editorial_story','product_collection','service_proof','trust_strip','category_links']),
                'defaults_json' => json_encode(['brand' => ['name' => 'Noir'], 'tokens' => ['colors' => ['ink' => '#EDEAE2', 'muted' => '#A8A294', 'paper' => '#16130F', 'surface' => '#201B15', 'accent' => '#D08A5A', 'accent_dark' => '#B96F42']]]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'forest',
                'name' => 'Forest',
                'version' => '1.0.0',
                'schema_version' => 1,
                'status' => 'active',
                'token_schema_json' => json_encode(['colors' => ['ink' => '#1C2620', 'muted' => '#5C6E60', 'paper' => '#F1F4EC', 'surface' => '#FBFDF7', 'accent' => '#2F6B3C', 'accent_dark' => '#234E2D']]),
                'allowed_section_types_json' => json_encode(['campaign_hero','collection_directory','editorial_story','product_collection','service_proof','trust_strip','category_links']),
                'defaults_json' => json_encode(['brand' => ['name' => 'Forest'], 'tokens' => ['colors' => ['ink' => '#1C2620', 'muted' => '#5C6E60', 'paper' => '#F1F4EC', 'surface' => '#FBFDF7', 'accent' => '#2F6B3C', 'accent_dark' => '#234E2D']]]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'seasonal',
                'name' => 'Seasonal',
                'version' => '1.0.0',
                'schema_version' => 1,
                'status' => 'active',
                'token_schema_json' => json_encode(['colors' => ['ink' => '#1A211D', 'muted' => '#5F6D61', 'paper' => '#F6F5EF', 'surface' => '#FFFEFA', 'accent' => '#7A3B2E', 'accent_dark' => '#5E2E24']]),
                'allowed_section_types_json' => json_encode(['campaign_hero','collection_directory','editorial_story','product_collection','service_proof','trust_strip','category_links']),
                'defaults_json' => json_encode(['brand' => ['name' => 'Seasonal', 'wordmark' => 'seasonal/edit'], 'tokens' => ['colors' => ['ink' => '#1A211D', 'muted' => '#5F6D61', 'paper' => '#F6F5EF', 'surface' => '#FFFEFA', 'accent' => '#7A3B2E', 'accent_dark' => '#5E2E24']]]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];
        foreach ($themes as $row) {
            try {
                $exists = Database::table('storefront_themes')->where('key', $row['key'])->where('version', $row['version'])->first();
                if ($exists === null) {
                    Database::table('storefront_themes')->insert($row);
                }
            } catch (\Throwable $e) {
                // table may not exist in test environments - ignore
            }
        }
    }

    public function down(): void
    {
        try {
            Database::table('storefront_themes')->whereIn('key', ['noir','forest','seasonal'])->delete();
        } catch (\Throwable $e) {}
    }
};
