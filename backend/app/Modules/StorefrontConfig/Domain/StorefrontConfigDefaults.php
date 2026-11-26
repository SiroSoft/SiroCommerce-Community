<?php
declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Domain;

final class StorefrontConfigDefaults
{
    /** @return array<string, mixed> */
    public static function config(string $storeId, string $channelId, string $locale, string $currency): array
    {
        return [
            'scope' => ['store_id' => $storeId, 'channel_id' => $channelId, 'locale' => $locale, 'currency' => $currency],
            'theme' => ['key' => 'editorial', 'version' => '1.0.0', 'schema_version' => 1, 'tokens' => [
                'colors' => ['ink' => '#1E2420', 'muted' => '#536057', 'paper' => '#F5F5F0', 'surface' => '#FFFEFA', 'accent' => '#883C29', 'accent_dark' => '#693021'],
                'typography' => ['display' => 'editorial', 'body' => 'system', 'density' => 'comfortable', 'display_stack' => '"Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif', 'body_stack' => '"Inter", -apple-system, "Segoe UI", Roboto, Arial, sans-serif', 'base_size' => 16],
                'shape' => ['radius' => 'soft', 'card' => 'bordered'],
            ]],
            'vertical' => ['key' => 'general', 'version' => '1.0.0'],
            'brand' => ['name' => 'SiroCommerce', 'wordmark' => 'siro/commerce', 'logo_url' => '/brand/siro-commerce-mark.svg', 'favicon_url' => null],
            'navigation' => ['menu_key' => 'primary', 'items' => self::defaultNavigation()],
            'header' => ['announcement' => true, 'layout' => 'standard', 'sticky' => false],
            'footer' => ['layout' => 'standard', 'show_help' => true, 'show_policies' => true, 'columns' => []],
            'homepage' => ['sections' => self::defaultSections()],
            'presentation' => ['product_card_variant' => 'editorial', 'product_detail_variant' => 'editorial', 'purchase_variant' => 'standard'],
            'purchase' => ['quote_mode' => 'required'],
            'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
            'experiments' => [],
            'version' => 1,
        ];
    }

    /** @return list<string> */
    public static function allowedSections(): array
    {
        return ['campaign_hero', 'collection_directory', 'editorial_story', 'product_collection', 'service_proof', 'trust_strip', 'category_links', 'banner_grid', 'usp_strip', 'faq'];
    }

    /** @return list<array<string, mixed>> */
    public static function defaultSections(): array
    {
        return [
            ['id' => 'campaign', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => []],
            ['id' => 'collections', 'type' => 'collection_directory', 'enabled' => true, 'order' => 20, 'props' => []],
            ['id' => 'promises', 'type' => 'usp_strip', 'enabled' => true, 'order' => 25, 'props' => ['items' => [
                ['icon' => 'truck', 'title' => 'Fast delivery', 'copy' => 'Dispatch within 24 hours on in-stock orders.'],
                ['icon' => 'shield', 'title' => 'Secure checkout', 'copy' => 'Encrypted payments and buyer protection on every order.'],
                ['icon' => 'refresh', 'title' => 'Easy returns', 'copy' => 'Return eligible items within 30 days.'],
            ]]],
            ['id' => 'story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 30, 'props' => []],
            ['id' => 'banner-grid', 'type' => 'banner_grid', 'enabled' => true, 'order' => 35, 'props' => ['banners' => [
                ['title' => 'New season edit', 'subtitle' => 'Fresh arrivals across every category.', 'href' => '/search?tag=new', 'image' => '/media/storefront/default-banner-1.jpg', 'cta' => ['label' => 'Shop new', 'href' => '/search?tag=new']],
                ['title' => 'Member favourites', 'subtitle' => 'Top picks chosen by our community.', 'href' => '/search?featured=1', 'image' => '/media/storefront/default-banner-2.jpg', 'cta' => ['label' => 'View favourites', 'href' => '/search?featured=1']],
            ]]],
            ['id' => 'new-edit', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'tag:new']],
            ['id' => 'favorites', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'featured']],
            ['id' => 'proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 60, 'props' => []],
            ['id' => 'faq', 'type' => 'faq', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                ['question' => 'How long does delivery take?', 'answer' => 'Most in-stock orders arrive within 2-5 business days.'],
                ['question' => 'Can I return an item?', 'answer' => 'Eligible items can be returned within 30 days of delivery.'],
                ['question' => 'How do I contact support?', 'answer' => 'Use the contact page or the support link in the footer.'],
            ]]],
        ];
    }

    /** @return list<array<string, string>> */
    public static function defaultNavigation(): array
    {
        return [
            ['label' => 'Apparel', 'href' => '/category/apparel'],
            ['label' => 'Home & Living', 'href' => '/category/home-living'],
            ['label' => 'Personal Care', 'href' => '/category/personal-care'],
            ['label' => 'Food & Beverage', 'href' => '/category/food-beverage'],
        ];
    }
}
