<?php
declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Infrastructure;

use App\Modules\StorefrontConfig\Domain\StorefrontConfigDefaults;
use App\Modules\StorefrontConfig\Domain\StorefrontVerticalPresetCatalog;

final class StorefrontConfigSeeder
{
    public static function seed(\PDO $pdo): void
    {
        $now = date('Y-m-d H:i:s');
        $theme = StorefrontConfigDefaults::config('default', 'default', 'en', 'USD')['theme'];
        $tokenSchema = ['colors' => ['hex' => true], 'typography' => ['display' => ['editorial'], 'body' => ['system']], 'shape' => ['radius' => ['soft'], 'card' => ['bordered']]];
        self::upsert($pdo, 'storefront_themes', ['key' => 'editorial', 'version' => '1.0.0'], ['name' => 'Editorial', 'schema_version' => 1, 'status' => 'active', 'token_schema_json' => json_encode($tokenSchema, JSON_THROW_ON_ERROR), 'allowed_section_types_json' => json_encode(StorefrontConfigDefaults::allowedSections(), JSON_THROW_ON_ERROR), 'defaults_json' => json_encode($theme, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        $legacyPresets = ['general' => ['product_card_variant'=>'editorial','product_detail_variant'=>'editorial','purchase_variant'=>'standard'], 'apparel' => ['product_card_variant'=>'editorial','product_detail_variant'=>'editorial','product_list_variant'=>'list-editorial','purchase_variant'=>'standard'], 'home' => ['product_card_variant'=>'editorial','product_detail_variant'=>'editorial','purchase_variant'=>'standard'], 'food_beverage' => ['product_card_variant'=>'editorial','product_detail_variant'=>'editorial','purchase_variant'=>'standard'], 'digital' => ['product_card_variant'=>'editorial','product_detail_variant'=>'editorial','purchase_variant'=>'standard'], 'marketplace' => ['product_card_variant'=>'editorial','product_detail_variant'=>'editorial','purchase_variant'=>'standard']];
        foreach ($legacyPresets as $key => $presentation) self::upsert($pdo, 'storefront_vertical_presets', ['key' => $key, 'version' => '1.0.0'], ['name' => ucwords(str_replace('_', ' ', $key)), 'status' => 'active', 'theme_defaults_json' => '{}', 'presentation_defaults_json' => json_encode($presentation, JSON_THROW_ON_ERROR), 'homepage_defaults_json' => '{"preferred_sections":[]}', 'validation_schema_json' => json_encode(['purchase_defaults' => ['quote_mode' => 'required']], JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        foreach (StorefrontVerticalPresetCatalog::all() as $key => $preset) {
            $presetTheme = $preset['theme'];
            self::upsert($pdo, 'storefront_themes', ['key' => $presetTheme['key'], 'version' => $presetTheme['version']], ['name' => $preset['name'], 'schema_version' => 1, 'status' => 'active', 'token_schema_json' => json_encode($tokenSchema, JSON_THROW_ON_ERROR), 'allowed_section_types_json' => json_encode(StorefrontConfigDefaults::allowedSections(), JSON_THROW_ON_ERROR), 'defaults_json' => json_encode($presetTheme, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
            self::upsert($pdo, 'storefront_vertical_presets', ['key' => $key, 'version' => '1.0.0'], [
                'name' => $preset['name'],
                'status' => 'active',
                'theme_defaults_json' => json_encode(['theme' => $presetTheme, 'brand' => $preset['brand'], 'header' => $preset['header'], 'footer' => $preset['footer'], 'policies' => $preset['policies']], JSON_THROW_ON_ERROR),
                'presentation_defaults_json' => json_encode($preset['presentation'], JSON_THROW_ON_ERROR),
                'homepage_defaults_json' => json_encode(['preferred_sections' => $preset['preferred_sections'], 'sections' => $preset['sections']], JSON_THROW_ON_ERROR),
                'validation_schema_json' => json_encode(['description' => $preset['description'], 'preview' => $preset['preview'], 'purchase_defaults' => $preset['purchase']], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([['default', 'default'], ['demo-store-main', 'demo-channel-1']] as [$store, $channel]) {
            $config = StorefrontConfigDefaults::config($store, $channel, 'en', 'USD');
            self::upsert($pdo, 'storefront_configs', ['store_id' => $store, 'channel_id' => $channel, 'locale' => 'en', 'currency' => 'USD'], ['theme_key' => 'editorial', 'vertical_key' => 'general', 'status' => 'published', 'version' => 1, 'config_json' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /** @param array<string, scalar|null> $where
     * @param array<string, scalar|null> $values
     */
    private static function upsert(\PDO $pdo, string $table, array $where, array $values): void
    {
        $conditions = implode(' AND ', array_map(static fn (string $key): string => '`' . $key . '`=?', array_keys($where)));
        $check = $pdo->prepare('SELECT 1 FROM `' . $table . '` WHERE ' . $conditions . ' LIMIT 1');
        $check->execute(array_values($where));
        if ($check->fetchColumn() !== false) {
            $set = implode(',', array_map(static fn (string $key): string => '`' . $key . '`=?', array_keys($values)));
            $statement = $pdo->prepare('UPDATE `' . $table . '` SET ' . $set . ' WHERE ' . $conditions);
            $statement->execute([...array_values($values), ...array_values($where)]);
            return;
        }
        $data = [...$where, ...$values];
        $columns = array_keys($data);
        $statement = $pdo->prepare('INSERT INTO `' . $table . '` (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $statement->execute(array_values($data));
    }
}
