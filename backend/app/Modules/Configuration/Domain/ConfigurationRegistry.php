<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain;

final class ConfigurationRegistry
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function groups(): array
    {
        return [
            [
                'key' => 'general',
                'name' => 'General Settings',
                'sections' => [
                    [
                        'key' => 'general',
                        'name' => 'General',
                        'fields' => [
                            ['key' => 'site_name', 'type' => 'text', 'label' => 'Site name', 'default' => 'SiroCommerce', 'required' => true, 'max' => 120],
                            ['key' => 'timezone', 'type' => 'select', 'label' => 'Timezone', 'options' => ['Asia/Ho_Chi_Minh', 'UTC'], 'default' => 'Asia/Ho_Chi_Minh'],
                            ['key' => 'units', 'type' => 'select', 'label' => 'Units', 'options' => ['metric', 'imperial'], 'default' => 'metric'],
                            ['key' => 'breadcrumbs', 'type' => 'boolean', 'label' => 'Enable breadcrumbs', 'default' => true],
                        ],
                    ],
                    [
                        'key' => 'affiliate',
                        'name' => 'Affiliate System',
                        'fields' => [
                            ['key' => 'enabled', 'type' => 'boolean', 'label' => 'Enable Affiliate', 'default' => false],
                        ],
                    ],
                ],
            ],
            [
                'key' => 'content',
                'name' => 'Content',
                'sections' => [
                    ['key' => 'header', 'name' => 'Content', 'fields' => [
                        ['key' => 'offer_title', 'type' => 'text', 'label' => 'Header offer title', 'max' => 200],
                        ['key' => 'custom_scripts', 'type' => 'textarea', 'label' => 'Custom scripts', 'max' => 2000],
                    ]],
                ],
            ],
            [
                'key' => 'design',
                'name' => 'Design',
                'sections' => [
                    ['key' => 'general', 'name' => 'General', 'fields' => [
                        ['key' => 'logo', 'type' => 'image', 'label' => 'Logo URL', 'max' => 512],
                        ['key' => 'favicon', 'type' => 'image', 'label' => 'Favicon URL', 'max' => 512],
                    ]],
                ],
            ],
            [
                'key' => 'catalog',
                'name' => 'Catalog',
                'sections' => [
                    ['key' => 'products', 'name' => 'Products', 'fields' => [
                        ['key' => 'review_enabled', 'type' => 'boolean', 'label' => 'Enable reviews', 'default' => true],
                        ['key' => 'attribute_share', 'type' => 'boolean', 'label' => 'Share attributes', 'default' => false],
                    ]],
                    ['key' => 'inventory', 'name' => 'Inventory', 'fields' => [
                        ['key' => 'allow_backorders', 'type' => 'boolean', 'label' => 'Allow back orders', 'default' => false],
                        ['key' => 'out_of_stock_threshold', 'type' => 'number', 'label' => 'Out of stock threshold', 'default' => 0],
                    ]],
                ],
            ],
            [
                'key' => 'customer',
                'name' => 'Customer',
                'sections' => [
                    ['key' => 'address', 'name' => 'Address', 'fields' => [
                        ['key' => 'country', 'type' => 'text', 'label' => 'Country', 'max' => 64],
                        ['key' => 'zip', 'type' => 'text', 'label' => 'Zip', 'max' => 20],
                    ]],
                    ['key' => 'settings', 'name' => 'Settings', 'fields' => [
                        ['key' => 'wishlist_enabled', 'type' => 'boolean', 'label' => 'Wishlist enabled', 'default' => true],
                        ['key' => 'newsletter_enabled', 'type' => 'boolean', 'label' => 'Newsletter', 'default' => true],
                    ]],
                ],
            ],
            [
                'key' => 'email',
                'name' => 'Email',
                'sections' => [
                    ['key' => 'general', 'name' => 'Email Settings', 'fields' => [
                        ['key' => 'sender_name', 'type' => 'text', 'label' => 'Sender name', 'max' => 120],
                        ['key' => 'shop_email', 'type' => 'text', 'label' => 'Shop email', 'max' => 254],
                    ]],
                ],
            ],
            [
                'key' => 'sales',
                'name' => 'Sales',
                'sections' => [
                    ['key' => 'shipping', 'name' => 'Shipping Settings', 'fields' => [
                        ['key' => 'origin_country', 'type' => 'text', 'label' => 'Origin country', 'max' => 64],
                    ]],
                    ['key' => 'payment_methods', 'name' => 'Payment Methods', 'fields' => [
                        ['key' => 'cod_enabled', 'type' => 'boolean', 'label' => 'COD enabled', 'default' => true],
                        ['key' => 'bank_transfer_enabled', 'type' => 'boolean', 'label' => 'Bank transfer enabled', 'default' => true],
                    ]],
                    ['key' => 'checkout', 'name' => 'Checkout', 'fields' => [
                        ['key' => 'guest_checkout', 'type' => 'boolean', 'label' => 'Guest checkout', 'default' => true],
                        ['key' => 'mini_cart', 'type' => 'boolean', 'label' => 'Mini cart', 'default' => true],
                    ]],
                ],
            ],
            [
                'key' => 'taxes',
                'name' => 'Taxes',
                'sections' => [
                    ['key' => 'general', 'name' => 'General', 'fields' => [
                        ['key' => 'rate', 'type' => 'number', 'label' => 'Tax rate %', 'default' => 10],
                    ]],
                ],
            ],
            [
                'key' => 'custom',
                'name' => 'Custom',
                'sections' => [
                    [
                        'key' => 'promo_banner',
                        'name' => 'Promo Banner',
                        'fields' => [
                            ['key' => 'title', 'type' => 'text', 'label' => 'Banner title', 'max' => 120, 'default' => ''],
                            ['key' => 'enabled', 'type' => 'boolean', 'label' => 'Enabled', 'default' => false],
                            ['key' => 'image', 'type' => 'image', 'label' => 'Image URL', 'max' => 512, 'default' => ''],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $groupKey, string $sectionKey): ?array
    {
        foreach (self::groups() as $g) {
            if ($g['key'] !== $groupKey) continue;
            foreach ($g['sections'] as $s) {
                if ($s['key'] === $sectionKey) return ['group' => $g, 'section' => $s];
            }
        }
        return null;
    }
}
