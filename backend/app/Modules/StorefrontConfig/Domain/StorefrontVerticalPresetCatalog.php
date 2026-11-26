<?php
declare(strict_types=1);

namespace App\Modules\StorefrontConfig\Domain;

/**
 * Full configuration starting points for industry verticals. Each preset owns
 * a child theme that extends the built-in `general` theme, its own tokens,
 * header/footer composition, homepage sections, presentation variants and
 * policy links. Presets are presentation-only; they never change commerce
 * truth (price, stock, availability, checkout).
 */
final class StorefrontVerticalPresetCatalog
{
    /**
     * @return array<string, array{
     *   name: string,
     *   description: string,
     *   preview: string,
     *   theme: array{key: string, extends: string, version: string, schema_version: int, tokens: array<string, mixed>},
     *   brand: array{name: string, wordmark: string, logo_url: string, favicon_url: null},
     *   header: array<string, mixed>,
     *   footer: array<string, mixed>,
     *   purchase: array{quote_mode: string},
     *   policies: array<string, string>,
     *   presentation: array<string, string>,
     *   preferred_sections: list<string>,
     *   sections: list<array<string, mixed>>,
     *   layout_archetype?: string
     * }>
     */
    public static function all(): array
    {
        $presets = [
            'beauty' => [
                'name' => 'Beauty & Personal Care',
                'description' => 'Soft, editorial beauty storefront with routine-led collections, trust proof and FAQ.',
                'preview' => '/media/storefront/previews/beauty.svg',
                'theme' => self::theme(
                    'beauty',
                    ['ink' => '#2A1F24', 'muted' => '#7A6470', 'paper' => '#FBF4F6', 'surface' => '#FFFCFD', 'accent' => '#C05A7A', 'accent_dark' => '#9A3F5E', 'success' => '#3F7A5A', 'danger' => '#B5443A'],
                    ['display' => 'elegant', 'density' => 'spacious', 'display_stack' => '"Playfair Display", Georgia, "Times New Roman", serif', 'base_size' => 16],
                    ['radius' => 'round', 'card' => 'elevated'],
                ),
                'brand' => ['name' => 'Beauty Studio', 'wordmark' => 'beauty/studio', 'logo_url' => '/brand/beauty-studio.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'centered', 'sticky' => true],
                'footer' => ['layout' => 'mega', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Shop', 'links' => [['label' => 'Skincare', 'href' => '/category/skincare'], ['label' => 'Makeup', 'href' => '/category/makeup'], ['label' => 'Fragrance', 'href' => '/category/fragrance']]],
                    ['title' => 'Beauty advice', 'links' => [['label' => 'Routine finder', 'href' => '/beauty-quiz'], ['label' => 'Ingredient guide', 'href' => '/ingredients'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'editorial', 'product_detail_variant' => 'editorial', 'product_list_variant' => 'list-editorial', 'purchase_variant' => 'standard'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'category_links', 'product_collection', 'editorial_story', 'usp_strip', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'beauty-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'A routine that feels like you', 'eyebrow' => 'Skin, hair and body', 'cta' => ['label' => 'Build your routine', 'href' => '/beauty-quiz']]],
                    ['id' => 'beauty-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Barrier-first skincare', 'subtitle' => 'Gentle daily essentials for every skin type.', 'href' => '/category/skincare', 'image' => '/media/storefront/beauty-skincare.jpg', 'cta' => ['label' => 'Shop skincare', 'href' => '/category/skincare']],
                        ['title' => 'Colour, softly', 'subtitle' => 'Buildable shades for everyday looks.', 'href' => '/category/makeup', 'image' => '/media/storefront/beauty-makeup.jpg', 'cta' => ['label' => 'Shop makeup', 'href' => '/category/makeup']],
                    ]]],
                    ['id' => 'beauty-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Shop by ritual']],
                    ['id' => 'beauty-bestsellers', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'best_selling', 'title' => 'Community favourites', 'eyebrow' => 'Most loved', 'limit' => 8, 'cta' => ['label' => 'See all bestsellers', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'beauty-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 50, 'props' => ['title' => 'The ingredient edit', 'eyebrow' => 'Know what you apply']],
                    ['id' => 'beauty-promises', 'type' => 'usp_strip', 'enabled' => true, 'order' => 60, 'props' => ['items' => [
                        ['icon' => 'leaf', 'title' => 'Considered formulas', 'copy' => 'Thoughtfully selected ingredients and clear labelling.'],
                        ['icon' => 'shield', 'title' => 'Dermatologist reviewed', 'copy' => 'Products reviewed for daily use and sensitive skin.'],
                        ['icon' => 'truck', 'title' => 'Fast dispatch', 'copy' => 'In-stock orders leave the warehouse within 24 hours.'],
                        ['icon' => 'refresh', 'title' => 'Easy returns', 'copy' => 'Eligible items can be returned within 30 days.'],
                    ]]],
                    ['id' => 'beauty-faq', 'type' => 'faq', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['question' => 'How do I find the right routine?', 'answer' => 'Use the routine finder or browse by skin concern. Each product page lists key ingredients and usage.'],
                        ['question' => 'Are products tested on animals?', 'answer' => 'We label every product clearly so you can filter by your preferences.'],
                        ['question' => 'When will my order arrive?', 'answer' => 'Most in-stock orders arrive within 2-5 business days. Tracking is sent as soon as the parcel leaves.'],
                        ['question' => 'Can I return opened products?', 'answer' => 'Unopened items can be returned within 30 days. Opened items are accepted only when faulty.'],
                    ]]],
                    ['id' => 'beauty-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 80, 'props' => []],
                ],
            ],
            'pharmacy' => [
                'name' => 'Pharmacy & Health',
                'description' => 'Trust-first pharmacy layout: pharmacist proof, essential categories, refill banners and health FAQ.',
                'preview' => '/media/storefront/previews/pharmacy.svg',
                'theme' => self::theme(
                    'pharmacy',
                    ['ink' => '#14232B', 'muted' => '#4E6570', 'paper' => '#F2F7F8', 'surface' => '#FFFFFF', 'accent' => '#0F7C8A', 'accent_dark' => '#0A5A65', 'success' => '#2E7D4F', 'danger' => '#B23B3B'],
                    ['display' => 'clean', 'density' => 'comfortable', 'display_stack' => '"Source Sans 3", "Segoe UI", Roboto, Arial, sans-serif', 'base_size' => 16],
                    ['radius' => 'soft', 'card' => 'bordered'],
                ),
                'brand' => ['name' => 'Care Pharmacy', 'wordmark' => 'care/pharmacy', 'logo_url' => '/brand/care-pharmacy.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'minimal', 'sticky' => true],
                'footer' => ['layout' => 'standard', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Health services', 'links' => [['label' => 'Pharmacist advice', 'href' => '/pharmacist-advice'], ['label' => 'Refill reminders', 'href' => '/refills'], ['label' => 'Health guides', 'href' => '/health-guides']]],
                    ['title' => 'Orders', 'links' => [['label' => 'Delivery information', 'href' => '/shipping'], ['label' => 'Returns', 'href' => '/returns'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'minimal', 'product_detail_variant' => 'focused', 'product_list_variant' => 'list-grid-wide', 'purchase_variant' => 'standard'],
                'preferred_sections' => ['campaign_hero', 'usp_strip', 'category_links', 'product_collection', 'editorial_story', 'faq', 'service_proof', 'banner_grid'],
                'sections' => [
                    ['id' => 'pharmacy-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Everyday health, clearly explained', 'eyebrow' => 'Pharmacy essentials', 'cta' => ['label' => 'Shop essentials', 'href' => '/category/health']]],
                    ['id' => 'pharmacy-trust', 'type' => 'usp_strip', 'enabled' => true, 'order' => 20, 'props' => ['items' => [
                        ['icon' => 'shield', 'title' => 'Licensed pharmacists', 'copy' => 'Questions answered by registered pharmacy staff.'],
                        ['icon' => 'check', 'title' => 'Genuine products', 'copy' => 'Sourced through authorised supply channels.'],
                        ['icon' => 'truck', 'title' => 'Discreet delivery', 'copy' => 'Plain packaging and tracked delivery on every order.'],
                        ['icon' => 'calendar', 'title' => 'Refill reminders', 'copy' => 'Optional reminders for repeat prescriptions.'],
                    ]]],
                    ['id' => 'pharmacy-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Shop by health need']],
                    ['id' => 'pharmacy-essentials', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'best_selling', 'title' => 'Everyday essentials', 'eyebrow' => 'Always useful', 'limit' => 8, 'cta' => ['label' => 'See all essentials', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'pharmacy-advice', 'type' => 'editorial_story', 'enabled' => true, 'order' => 50, 'props' => ['title' => 'Health notes from our pharmacists', 'eyebrow' => 'Advice']],
                    ['id' => 'pharmacy-faq', 'type' => 'faq', 'enabled' => true, 'order' => 60, 'props' => ['items' => [
                        ['question' => 'Can I ask a pharmacist a question?', 'answer' => 'Yes. Use the pharmacist advice page and a registered pharmacist will respond during service hours.'],
                        ['question' => 'Are prescription items available online?', 'answer' => 'Prescription items are shown only where local rules allow. Availability is confirmed before dispatch.'],
                        ['question' => 'How are sensitive items packaged?', 'answer' => 'Orders ship in plain packaging with no product names on the outside.'],
                        ['question' => 'What if I need a refill?', 'answer' => 'Enable refill reminders from your account and we will notify you before you run out.'],
                        ['question' => 'Can I return health products?', 'answer' => 'For safety, opened health products cannot be returned unless they are faulty or incorrect.'],
                    ]]],
                    ['id' => 'pharmacy-service', 'type' => 'service_proof', 'enabled' => true, 'order' => 70, 'props' => []],
                    ['id' => 'pharmacy-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 80, 'props' => ['banners' => [
                        ['title' => 'Set up refill reminders', 'subtitle' => 'Never miss a repeat order again.', 'href' => '/refills', 'image' => '/media/storefront/pharmacy-refills.jpg', 'cta' => ['label' => 'Manage refills', 'href' => '/refills']],
                        ['title' => 'Ask a pharmacist', 'subtitle' => 'Clear answers before you buy.', 'href' => '/pharmacist-advice', 'image' => '/media/storefront/pharmacy-advice.jpg', 'cta' => ['label' => 'Ask now', 'href' => '/pharmacist-advice']],
                    ]]],
                ],
            ],
            'fashion' => [
                'name' => 'Fashion & Apparel',
                'description' => 'Editorial fashion storefront with lookbook banners, new-season collections and size/returns FAQ.',
                'preview' => '/media/storefront/previews/fashion.svg',
                'theme' => self::theme(
                    'fashion',
                    ['ink' => '#111111', 'muted' => '#6B6B6B', 'paper' => '#F7F6F3', 'surface' => '#FFFFFF', 'accent' => '#9B2C2C', 'accent_dark' => '#742020', 'success' => '#35664A', 'danger' => '#A13F35'],
                    ['display' => 'editorial', 'density' => 'comfortable', 'display_stack' => '"Bodoni 72", "Didot", "Times New Roman", serif', 'base_size' => 16],
                    ['radius' => 'sharp', 'card' => 'flat'],
                ),
                'brand' => ['name' => 'Atelier Edit', 'wordmark' => 'atelier/edit', 'logo_url' => '/brand/atelier-edit.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'centered', 'sticky' => true],
                'footer' => ['layout' => 'mega', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Shop', 'links' => [['label' => 'New in', 'href' => '/search?tag=new'], ['label' => 'Clothing', 'href' => '/category/clothing'], ['label' => 'Accessories', 'href' => '/category/accessories']]],
                    ['title' => 'Help', 'links' => [['label' => 'Size guide', 'href' => '/size-guide'], ['label' => 'Shipping', 'href' => '/shipping'], ['label' => 'Returns', 'href' => '/returns']]],
                    ['title' => 'About', 'links' => [['label' => 'Our story', 'href' => '/about'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'editorial', 'product_detail_variant' => 'page-gallery-left', 'product_list_variant' => 'list-editorial', 'purchase_variant' => 'compact'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'collection_directory', 'product_collection', 'editorial_story', 'category_links', 'usp_strip', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'fashion-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'The new season edit', 'eyebrow' => 'Lookbook', 'cta' => ['label' => 'Shop new in', 'href' => '/search?tag=new']]],
                    ['id' => 'fashion-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Tailored layers', 'subtitle' => 'Clean lines and quiet colour.', 'href' => '/collection/tailored-layers', 'image' => '/media/storefront/fashion-tailoring.jpg', 'cta' => ['label' => 'View edit', 'href' => '/collection/tailored-layers']],
                        ['title' => 'Weekend denim', 'subtitle' => 'Relaxed fits for off-duty days.', 'href' => '/collection/weekend-denim', 'image' => '/media/storefront/fashion-denim.jpg', 'cta' => ['label' => 'Shop denim', 'href' => '/collection/weekend-denim']],
                    ]]],
                    ['id' => 'fashion-collections', 'type' => 'collection_directory', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Collections']],
                    ['id' => 'fashion-new', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'tag:new', 'title' => 'Just arrived', 'eyebrow' => 'New in', 'limit' => 8, 'cta' => ['label' => 'Shop new arrivals', 'href' => '/search?tag=new']]],
                    ['id' => 'fashion-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 50, 'props' => ['title' => 'The studio notes', 'eyebrow' => 'Journal']],
                    ['id' => 'fashion-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 60, 'props' => ['title' => 'Shop by category']],
                    ['id' => 'fashion-promises', 'type' => 'usp_strip', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['icon' => 'ruler', 'title' => 'Detailed size guide', 'copy' => 'Measurements and fit notes on every product page.'],
                        ['icon' => 'truck', 'title' => 'Tracked delivery', 'copy' => 'Orders are tracked from dispatch to doorstep.'],
                        ['icon' => 'refresh', 'title' => '30-day returns', 'copy' => 'Return eligible unworn items within 30 days.'],
                    ]]],
                    ['id' => 'fashion-faq', 'type' => 'faq', 'enabled' => true, 'order' => 80, 'props' => ['items' => [
                        ['question' => 'How do I choose a size?', 'answer' => 'Each product page includes a size guide with measurements and fit notes.'],
                        ['question' => 'Can I exchange an item?', 'answer' => 'Eligible unworn items can be exchanged within 30 days of delivery.'],
                        ['question' => 'When is new stock added?', 'answer' => 'New arrivals are added regularly. Check the new in collection for the latest drop.'],
                    ]]],
                    ['id' => 'fashion-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 90, 'props' => []],
                ],
            ],
            'fnb' => [
                'name' => 'Food & Beverage',
                'description' => 'Appetite-led food and beverage layout with category shortcuts, banners and delivery FAQ.',
                'preview' => '/media/storefront/previews/fnb.svg',
                'theme' => self::theme(
                    'fnb',
                    ['ink' => '#2B1D14', 'muted' => '#6F5A48', 'paper' => '#FFF8EE', 'surface' => '#FFFCF6', 'accent' => '#C4551F', 'accent_dark' => '#9C3F14', 'success' => '#3E7D3A', 'danger' => '#B23B2E'],
                    ['display' => 'friendly', 'density' => 'comfortable', 'display_stack' => '"Nunito", "Segoe UI", Roboto, Arial, sans-serif', 'base_size' => 16],
                    ['radius' => 'round', 'card' => 'elevated'],
                ),
                'brand' => ['name' => 'Daily Table', 'wordmark' => 'daily/table', 'logo_url' => '/brand/daily-table.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'standard', 'sticky' => false],
                'footer' => ['layout' => 'minimal', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Menu', 'links' => [['label' => 'Coffee', 'href' => '/category/coffee'], ['label' => 'Pantry', 'href' => '/category/pantry'], ['label' => 'Gifts', 'href' => '/category/gifts']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'editorial', 'product_detail_variant' => 'focused', 'product_list_variant' => 'list-grid-wide', 'purchase_variant' => 'compact'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'category_links', 'product_collection', 'editorial_story', 'usp_strip', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'fnb-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Made for slow mornings', 'eyebrow' => 'Fresh roast', 'cta' => ['label' => 'Shop coffee', 'href' => '/category/coffee']]],
                    ['id' => 'fnb-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Coffee subscription', 'subtitle' => 'Freshly roasted beans delivered on your schedule.', 'href' => '/collections/coffee-subscription', 'image' => '/media/storefront/fnb-coffee.jpg', 'cta' => ['label' => 'Start subscription', 'href' => '/collections/coffee-subscription']],
                        ['title' => 'Pantry staples', 'subtitle' => 'Small-batch essentials for everyday cooking.', 'href' => '/category/pantry', 'image' => '/media/storefront/fnb-pantry.jpg', 'cta' => ['label' => 'Stock the pantry', 'href' => '/category/pantry']],
                    ]]],
                    ['id' => 'fnb-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Browse the menu']],
                    ['id' => 'fnb-bestsellers', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'best_selling', 'title' => 'Customer favourites', 'eyebrow' => 'Best sellers', 'limit' => 8, 'cta' => ['label' => 'See all favourites', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'fnb-featured', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'featured', 'title' => 'This week on the menu', 'eyebrow' => 'Featured', 'limit' => 4, 'cta' => ['label' => 'See featured', 'href' => '/search?featured=1']]],
                    ['id' => 'fnb-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 60, 'props' => ['title' => 'From our kitchen', 'eyebrow' => 'Stories']],
                    ['id' => 'fnb-promises', 'type' => 'usp_strip', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['icon' => 'leaf', 'title' => 'Freshly prepared', 'copy' => 'Small batches prepared close to dispatch.'],
                        ['icon' => 'truck', 'title' => 'Chilled delivery', 'copy' => 'Temperature-aware packaging where required.'],
                        ['icon' => 'store', 'title' => 'Pickup available', 'copy' => 'Collect in store at a time that suits you.'],
                    ]]],
                    ['id' => 'fnb-faq', 'type' => 'faq', 'enabled' => true, 'order' => 80, 'props' => ['items' => [
                        ['question' => 'How is fresh food delivered?', 'answer' => 'Chilled items ship in insulated packaging with tracking. Delivery windows are shown at checkout.'],
                        ['question' => 'Can I pause a subscription?', 'answer' => 'Yes. Pause, skip or cancel from your account before the next billing date.'],
                        ['question' => 'Do you offer pickup?', 'answer' => 'Select pickup at checkout to collect from the store during opening hours.'],
                        ['question' => 'What is your returns policy?', 'answer' => 'For food safety, perishable items cannot be returned unless faulty or incorrect.'],
                    ]]],
                    ['id' => 'fnb-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 90, 'props' => []],
                ],
            ],
            'electronics' => [
                'name' => 'Electronics & Technology',
                'description' => 'Spec-first electronics storefront with compare banners, warranty proof and technical FAQ.',
                'preview' => '/media/storefront/previews/electronics.svg',
                'theme' => self::theme(
                    'electronics',
                    ['ink' => '#0E1622', 'muted' => '#536274', 'paper' => '#F4F7FA', 'surface' => '#FFFFFF', 'accent' => '#0B6BCB', 'accent_dark' => '#084F97', 'success' => '#1F7A4D', 'danger' => '#B3261E'],
                    ['display' => 'modern', 'density' => 'compact', 'display_stack' => '"Inter", -apple-system, "Segoe UI", Roboto, Arial, sans-serif', 'base_size' => 15],
                    ['radius' => 'sharp', 'card' => 'bordered'],
                ),
                'brand' => ['name' => 'Circuit Store', 'wordmark' => 'circuit/store', 'logo_url' => '/brand/circuit-store.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'split', 'sticky' => true],
                'footer' => ['layout' => 'mega', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Products', 'links' => [['label' => 'Audio', 'href' => '/category/audio'], ['label' => 'Computing', 'href' => '/category/computing'], ['label' => 'Smart home', 'href' => '/category/smart-home']]],
                    ['title' => 'Support', 'links' => [['label' => 'Product support', 'href' => '/support'], ['label' => 'Warranty', 'href' => '/warranty'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'minimal', 'product_detail_variant' => 'focused', 'product_list_variant' => 'list-grid-wide', 'purchase_variant' => 'standard'],
                'preferred_sections' => ['campaign_hero', 'category_links', 'product_collection', 'trust_strip', 'usp_strip', 'editorial_story', 'faq', 'banner_grid', 'service_proof'],
                'sections' => [
                    ['id' => 'electronics-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Compare before you choose', 'eyebrow' => 'New technology', 'cta' => ['label' => 'Shop new arrivals', 'href' => '/search?tag=new']]],
                    ['id' => 'electronics-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 20, 'props' => ['title' => 'Shop by category']],
                    ['id' => 'electronics-bestsellers', 'type' => 'product_collection', 'enabled' => true, 'order' => 30, 'props' => ['source' => 'best_selling', 'title' => 'Top rated technology', 'eyebrow' => 'Best sellers', 'limit' => 8, 'cta' => ['label' => 'See all top rated', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'electronics-new', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'tag:new', 'title' => 'Just launched', 'eyebrow' => 'New in', 'limit' => 8, 'cta' => ['label' => 'Shop new', 'href' => '/search?tag=new']]],
                    ['id' => 'electronics-trust', 'type' => 'trust_strip', 'enabled' => true, 'order' => 50, 'props' => ['title' => 'Authorised retailer', 'copy' => 'Genuine products with manufacturer warranty.']],
                    ['id' => 'electronics-specs', 'type' => 'usp_strip', 'enabled' => true, 'order' => 60, 'props' => ['items' => [
                        ['icon' => 'list', 'title' => 'Clear specifications', 'copy' => 'Key specs and compatibility listed on every product page.'],
                        ['icon' => 'shield', 'title' => 'Manufacturer warranty', 'copy' => 'Warranty coverage shown before you add to cart.'],
                        ['icon' => 'swap', 'title' => 'Trade-in options', 'copy' => 'Trade in eligible devices for credit where available.'],
                        ['icon' => 'truck', 'title' => 'Fast shipping', 'copy' => 'Tracked dispatch on in-stock technology.'],
                    ]]],
                    ['id' => 'electronics-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 70, 'props' => ['title' => 'Buying guides', 'eyebrow' => 'Guides']],
                    ['id' => 'electronics-faq', 'type' => 'faq', 'enabled' => true, 'order' => 80, 'props' => ['items' => [
                        ['question' => 'How do I compare two products?', 'answer' => 'Open the compare view from any product card. Specifications and compatibility are aligned side by side.'],
                        ['question' => 'Is warranty included?', 'answer' => 'Yes. Warranty length and coverage are shown on each product page and on your order confirmation.'],
                        ['question' => 'Can I return an opened device?', 'answer' => 'Eligible devices can be returned within the stated window if they are in resaleable condition.'],
                        ['question' => 'Do you ship internationally?', 'answer' => 'Shipping destinations are confirmed at checkout based on the selected store and channel.'],
                    ]]],
                    ['id' => 'electronics-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 90, 'props' => ['banners' => [
                        ['title' => 'Build your setup', 'subtitle' => 'Bundles for work, study and play.', 'href' => '/collections/setups', 'image' => '/media/storefront/electronics-setups.jpg', 'cta' => ['label' => 'Shop bundles', 'href' => '/collections/setups']],
                        ['title' => 'Compare devices', 'subtitle' => 'Specifications aligned in one view.', 'href' => '/compare', 'image' => '/media/storefront/electronics-compare.jpg', 'cta' => ['label' => 'Compare now', 'href' => '/compare']],
                    ]]],
                    ['id' => 'electronics-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 100, 'props' => []],
                ],
            ],
            'b2b' => [
                'name' => 'B2B & Wholesale',
                'description' => 'Company-first wholesale layout with quote request, bulk pricing proof and procurement FAQ.',
                'preview' => '/media/storefront/previews/b2b.svg',
                'theme' => self::theme(
                    'b2b',
                    ['ink' => '#12202F', 'muted' => '#4F6273', 'paper' => '#F3F5F8', 'surface' => '#FFFFFF', 'accent' => '#1E4E79', 'accent_dark' => '#143A5A', 'success' => '#2E6B4F', 'danger' => '#A33A32'],
                    ['display' => 'corporate', 'density' => 'compact', 'display_stack' => '"IBM Plex Sans", "Segoe UI", Roboto, Arial, sans-serif', 'base_size' => 15],
                    ['radius' => 'sharp', 'card' => 'bordered'],
                ),
                'brand' => ['name' => 'Supply Desk', 'wordmark' => 'supply/desk', 'logo_url' => '/brand/supply-desk.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'split', 'sticky' => true],
                'footer' => ['layout' => 'mega', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Procurement', 'links' => [['label' => 'Request a quote', 'href' => '/quote'], ['label' => 'Bulk pricing', 'href' => '/bulk-pricing'], ['label' => 'Catalog', 'href' => '/catalog']]],
                    ['title' => 'Company', 'links' => [['label' => 'About us', 'href' => '/about'], ['label' => 'Terms', 'href' => '/terms'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'required'],
                'presentation' => ['product_card_variant' => 'minimal', 'product_detail_variant' => 'focused', 'product_list_variant' => 'list-grid-wide', 'purchase_variant' => 'standard'],
                'preferred_sections' => ['campaign_hero', 'usp_strip', 'category_links', 'collection_directory', 'product_collection', 'editorial_story', 'faq', 'banner_grid', 'service_proof'],
                'sections' => [
                    ['id' => 'b2b-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Sourcing built around your business', 'eyebrow' => 'Wholesale', 'cta' => ['label' => 'Request a quote', 'href' => '/quote']]],
                    ['id' => 'b2b-promises', 'type' => 'usp_strip', 'enabled' => true, 'order' => 20, 'props' => ['items' => [
                        ['icon' => 'tag', 'title' => 'Tiered bulk pricing', 'copy' => 'Volume pricing applied automatically on eligible lines.'],
                        ['icon' => 'document', 'title' => 'Quote workflow', 'copy' => 'Request, review and approve quotes in one place.'],
                        ['icon' => 'user', 'title' => 'Dedicated account manager', 'copy' => 'A named contact for orders and follow-up.'],
                        ['icon' => 'calendar', 'title' => 'Net terms', 'copy' => 'Payment terms available after account approval.'],
                    ]]],
                    ['id' => 'b2b-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Shop by category']],
                    ['id' => 'b2b-collections', 'type' => 'collection_directory', 'enabled' => true, 'order' => 40, 'props' => ['title' => 'Company catalogues']],
                    ['id' => 'b2b-featured', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'featured', 'title' => 'Popular with procurement teams', 'eyebrow' => 'Featured', 'limit' => 8, 'cta' => ['label' => 'See featured lines', 'href' => '/search?featured=1']]],
                    ['id' => 'b2b-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 60, 'props' => ['title' => 'How we support your team', 'eyebrow' => 'Account support']],
                    ['id' => 'b2b-faq', 'type' => 'faq', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['question' => 'How do I open a business account?', 'answer' => 'Submit a company account request with your business details. Approval unlocks company pricing and quote tools.'],
                        ['question' => 'Is there a minimum order quantity?', 'answer' => 'Minimum quantities are shown on each product line and may vary by category.'],
                        ['question' => 'How do quotes work?', 'answer' => 'Add lines to a quote, submit it, and your account manager will confirm pricing and lead time.'],
                        ['question' => 'Which payment terms are available?', 'answer' => 'Net terms may be offered after account review. Available terms are shown on the approved account.'],
                    ]]],
                    ['id' => 'b2b-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 80, 'props' => ['banners' => [
                        ['title' => 'Request a quote', 'subtitle' => 'Send your line items and receive a response.', 'href' => '/quote', 'image' => '/media/storefront/b2b-quote.jpg', 'cta' => ['label' => 'Start a quote', 'href' => '/quote']],
                        ['title' => 'Download the catalog', 'subtitle' => 'Browse the full wholesale range.', 'href' => '/catalog', 'image' => '/media/storefront/b2b-catalog.jpg', 'cta' => ['label' => 'View catalog', 'href' => '/catalog']],
                    ]]],
                    ['id' => 'b2b-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 90, 'props' => []],
                ],
            ],
            'grocery' => [
                'name' => 'Grocery & Everyday Essentials',
                'description' => 'Bright, value-led grocery storefront with aisle shortcuts, promo banners and price/trust proof.',
                'preview' => '/media/storefront/previews/grocery.svg',
                'theme' => self::theme(
                    'grocery',
                    ['ink' => '#16301F', 'muted' => '#5C6E60', 'paper' => '#F4FAF2', 'surface' => '#FFFFFF', 'accent' => '#2E8B57', 'accent_dark' => '#1F6B40', 'success' => '#2E7D32', 'danger' => '#C0392B'],
                    ['display' => 'friendly', 'density' => 'compact', 'display_stack' => '"Nunito", "Segoe UI", Roboto, Arial, sans-serif', 'base_size' => 15],
                    ['radius' => 'round', 'card' => 'elevated'],
                ),
                'brand' => ['name' => 'Green Basket', 'wordmark' => 'green/basket', 'logo_url' => '/brand/green-basket.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'standard', 'sticky' => true],
                'footer' => ['layout' => 'mega', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Shop by aisle', 'links' => [['label' => 'Fresh produce', 'href' => '/category/fresh-produce'], ['label' => 'Pantry', 'href' => '/category/pantry'], ['label' => 'Household', 'href' => '/category/household']]],
                    ['title' => 'Help', 'links' => [['label' => 'Delivery slots', 'href' => '/shipping'], ['label' => 'Returns', 'href' => '/returns'], ['label' => 'Weekly deals', 'href' => '/deals']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'minimal', 'product_detail_variant' => 'focused', 'product_list_variant' => 'list-grid-wide', 'purchase_variant' => 'compact'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'category_links', 'product_collection', 'usp_strip', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'grocery-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Everyday value, fresher every week', 'eyebrow' => 'Groceries & essentials', 'cta' => ['label' => 'Shop weekly deals', 'href' => '/deals']]],
                    ['id' => 'grocery-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Weekly price drops', 'subtitle' => 'Everyday staples at lower prices this week.', 'href' => '/deals', 'image' => '/media/storefront/grocery-deals.jpg', 'cta' => ['label' => 'See the deals', 'href' => '/deals']],
                        ['title' => 'Fresh picks', 'subtitle' => 'Produce selected and packed close to delivery.', 'href' => '/category/fresh-produce', 'image' => '/media/storefront/grocery-produce.jpg', 'cta' => ['label' => 'Shop fresh', 'href' => '/category/fresh-produce']],
                    ]]],
                    ['id' => 'grocery-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Shop by aisle']],
                    ['id' => 'grocery-bestsellers', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'best_selling', 'title' => 'Weekly bestsellers', 'eyebrow' => 'Most added', 'limit' => 10, 'cta' => ['label' => 'See all bestsellers', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'grocery-stockup', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'featured', 'title' => 'Stock-up favourites', 'eyebrow' => 'Buy more, save more', 'limit' => 6, 'cta' => ['label' => 'Shop featured', 'href' => '/search?featured=1']]],
                    ['id' => 'grocery-promises', 'type' => 'usp_strip', 'enabled' => true, 'order' => 60, 'props' => ['items' => [
                        ['icon' => 'tag', 'title' => 'Best price promise', 'copy' => 'Clear everyday pricing with weekly savings marked.'],
                        ['icon' => 'shield', 'title' => 'Quality checked', 'copy' => 'Fresh items inspected before they leave the store.'],
                        ['icon' => 'truck', 'title' => 'Same-day slots', 'copy' => 'Choose a delivery window that fits your day.'],
                        ['icon' => 'store', 'title' => 'Click & collect', 'copy' => 'Order online and collect in store at no extra cost.'],
                    ]]],
                    ['id' => 'grocery-faq', 'type' => 'faq', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['question' => 'Which areas do you deliver to?', 'answer' => 'Delivery areas and available slots are confirmed at checkout using your postcode.'],
                        ['question' => 'What happens if an item is out of stock?', 'answer' => 'You can allow substitutions at checkout. If none suit you, the item is refunded.'],
                        ['question' => 'Do you price match?', 'answer' => 'Eligible everyday items follow our price promise. Ask support for details on a specific product.'],
                        ['question' => 'Can I return groceries?', 'answer' => 'For food safety, perishable items cannot be returned unless faulty or incorrect.'],
                    ]]],
                    ['id' => 'grocery-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 80, 'props' => []],
                ],
            ],
            'cosmetics' => [
                'name' => 'Cosmetics & Skincare',
                'description' => 'Pastel skincare and makeup storefront with routine-led collections, ingredient proof and review-led FAQ.',
                'preview' => '/media/storefront/previews/cosmetics.svg',
                'theme' => self::theme(
                    'cosmetics',
                    ['ink' => '#3B2C33', 'muted' => '#7E6A72', 'paper' => '#FBF3F4', 'surface' => '#FFFDFE', 'accent' => '#D98A9A', 'accent_dark' => '#B56A7C', 'success' => '#4E7C5C', 'danger' => '#B04A4A'],
                    ['display' => 'delicate', 'density' => 'spacious', 'display_stack' => '"Cormorant Garamond", Georgia, "Times New Roman", serif', 'base_size' => 16],
                    ['radius' => 'round', 'card' => 'elevated'],
                ),
                'brand' => ['name' => 'Petal Lab', 'wordmark' => 'petal/lab', 'logo_url' => '/brand/petal-lab.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'centered', 'sticky' => true],
                'footer' => ['layout' => 'mega', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Shop', 'links' => [['label' => 'Skincare', 'href' => '/category/skincare'], ['label' => 'Makeup', 'href' => '/category/makeup'], ['label' => 'Sets', 'href' => '/category/sets']]],
                    ['title' => 'Learn', 'links' => [['label' => 'Routine builder', 'href' => '/routine'], ['label' => 'Ingredient glossary', 'href' => '/ingredients'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'spotlight', 'product_detail_variant' => 'focused', 'product_list_variant' => 'list-editorial', 'purchase_variant' => 'standard'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'category_links', 'product_collection', 'editorial_story', 'usp_strip', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'cosmetics-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Skin first, colour second', 'eyebrow' => 'Skincare & makeup', 'cta' => ['label' => 'Build your routine', 'href' => '/routine']]],
                    ['id' => 'cosmetics-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Barrier care edit', 'subtitle' => 'Gentle hydration for a resilient daily routine.', 'href' => '/category/skincare', 'image' => '/media/storefront/cosmetics-skincare.jpg', 'cta' => ['label' => 'Shop skincare', 'href' => '/category/skincare']],
                        ['title' => 'Everyday colour', 'subtitle' => 'Sheer, buildable shades for easy looks.', 'href' => '/category/makeup', 'image' => '/media/storefront/cosmetics-makeup.jpg', 'cta' => ['label' => 'Shop makeup', 'href' => '/category/makeup']],
                    ]]],
                    ['id' => 'cosmetics-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Shop by step']],
                    ['id' => 'cosmetics-reviewed', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'best_selling', 'title' => 'Most reviewed', 'eyebrow' => 'Community tested', 'limit' => 8, 'cta' => ['label' => 'See top rated', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'cosmetics-new', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'featured', 'title' => 'New formulas', 'eyebrow' => 'Fresh launch', 'limit' => 6, 'cta' => ['label' => 'Shop new', 'href' => '/search?featured=1']]],
                    ['id' => 'cosmetics-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 60, 'props' => ['title' => 'Ingredient notes', 'eyebrow' => 'Learn']],
                    ['id' => 'cosmetics-usp', 'type' => 'usp_strip', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['icon' => 'leaf', 'title' => 'Ingredients listed', 'copy' => 'Every formula shows actives and full ingredient lists.'],
                        ['icon' => 'check', 'title' => 'Dermatologist reviewed', 'copy' => 'Products reviewed for daily use on sensitive skin.'],
                        ['icon' => 'refresh', 'title' => 'Gentle returns', 'copy' => 'Unopened items can be returned within 30 days.'],
                    ]]],
                    ['id' => 'cosmetics-faq', 'type' => 'faq', 'enabled' => true, 'order' => 80, 'props' => ['items' => [
                        ['question' => 'How do I build a routine?', 'answer' => 'Use the routine builder or follow the step labels on each product page for cleanse, treat and protect.'],
                        ['question' => 'Where are ingredients listed?', 'answer' => 'Each product page includes the full ingredient list and the key actives.'],
                        ['question' => 'What if my skin reacts?', 'answer' => 'Stop use and contact support. Unopened products can be returned; opened products are reviewed case by case.'],
                        ['question' => 'Do you test on animals?', 'answer' => 'Product pages label cruelty-free status so you can filter by your preference.'],
                    ]]],
                    ['id' => 'cosmetics-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 90, 'props' => []],
                ],
            ],
            'footwear' => [
                'name' => 'Footwear & Sneakers',
                'description' => 'Size-first footwear storefront with lookbook banners, fit guidance and size/returns FAQ.',
                'preview' => '/media/storefront/previews/footwear.svg',
                'theme' => self::theme(
                    'footwear',
                    ['ink' => '#1C1C1C', 'muted' => '#616161', 'paper' => '#F6F4EF', 'surface' => '#FFFFFF', 'accent' => '#D9480F', 'accent_dark' => '#A93A0B', 'success' => '#3C7A4E', 'danger' => '#B23A2E'],
                    ['display' => 'modern', 'density' => 'compact', 'display_stack' => '"Inter", -apple-system, "Segoe UI", Roboto, Arial, sans-serif', 'base_size' => 15],
                    ['radius' => 'soft', 'card' => 'bordered'],
                ),
                'brand' => ['name' => 'Stride Room', 'wordmark' => 'stride/room', 'logo_url' => '/brand/stride-room.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'split', 'sticky' => true],
                'footer' => ['layout' => 'standard', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Shop', 'links' => [['label' => 'Sneakers', 'href' => '/category/sneakers'], ['label' => 'Boots', 'href' => '/category/boots'], ['label' => 'Sandals', 'href' => '/category/sandals']]],
                    ['title' => 'Help', 'links' => [['label' => 'Size guide', 'href' => '/size-guide'], ['label' => 'Shipping', 'href' => '/shipping'], ['label' => 'Returns', 'href' => '/returns']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'minimal', 'product_detail_variant' => 'split-buybox', 'product_list_variant' => 'list-grid-wide', 'purchase_variant' => 'compact'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'category_links', 'product_collection', 'usp_strip', 'faq', 'service_proof', 'editorial_story'],
                'sections' => [
                    ['id' => 'footwear-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Find your fit, first time', 'eyebrow' => 'New season footwear', 'cta' => ['label' => 'Shop new arrivals', 'href' => '/search?tag=new']]],
                    ['id' => 'footwear-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Street lookbook', 'subtitle' => 'Everyday silhouettes with all-day cushioning.', 'href' => '/collection/street', 'image' => '/media/storefront/footwear-street.jpg', 'cta' => ['label' => 'View lookbook', 'href' => '/collection/street']],
                        ['title' => 'Trail ready', 'subtitle' => 'Grip and support for off-road days.', 'href' => '/collection/trail', 'image' => '/media/storefront/footwear-trail.jpg', 'cta' => ['label' => 'Shop trail', 'href' => '/collection/trail']],
                    ]]],
                    ['id' => 'footwear-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Shop by style']],
                    ['id' => 'footwear-new', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'tag:new', 'title' => 'Just landed', 'eyebrow' => 'New in', 'limit' => 8, 'cta' => ['label' => 'Shop new', 'href' => '/search?tag=new']]],
                    ['id' => 'footwear-bestsellers', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'best_selling', 'title' => 'Most loved fits', 'eyebrow' => 'Best sellers', 'limit' => 8, 'cta' => ['label' => 'See all bestsellers', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'footwear-usp', 'type' => 'usp_strip', 'enabled' => true, 'order' => 60, 'props' => ['items' => [
                        ['icon' => 'ruler', 'title' => 'Size guidance', 'copy' => 'Measurements and fit notes on every product page.'],
                        ['icon' => 'swap', 'title' => 'Free size exchange', 'copy' => 'Exchange an unworn pair for another size at no cost.'],
                        ['icon' => 'truck', 'title' => 'Tracked delivery', 'copy' => 'Every order ships tracked from dispatch to doorstep.'],
                        ['icon' => 'shield', 'title' => 'Quality build', 'copy' => 'Materials and construction details are listed up front.'],
                    ]]],
                    ['id' => 'footwear-faq', 'type' => 'faq', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['question' => 'How do I choose a size?', 'answer' => 'Compare your foot length with the size chart on each product and read the fit notes.'],
                        ['question' => 'Do you offer half sizes?', 'answer' => 'Available sizes are shown on the product page. If a half size is not listed, the style runs in whole sizes.'],
                        ['question' => 'Can I exchange for another size?', 'answer' => 'Unworn pairs can be exchanged within 30 days. Start the exchange from your order page.'],
                        ['question' => 'How long does break-in take?', 'answer' => 'Most styles settle within a few wears. Care and break-in notes are included with the pair.'],
                    ]]],
                    ['id' => 'footwear-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 80, 'props' => []],
                ],
            ],
            'jewelry' => [
                'name' => 'Jewelry & Fine Accessories',
                'description' => 'Refined jewelry storefront with spotlight collections, certification proof and warranty FAQ.',
                'preview' => '/media/storefront/previews/jewelry.svg',
                'theme' => self::theme(
                    'jewelry',
                    ['ink' => '#2A2119', 'muted' => '#766A5B', 'paper' => '#FBF7F0', 'surface' => '#FFFDF9', 'accent' => '#B08D57', 'accent_dark' => '#8A6A3A', 'success' => '#4F7A5B', 'danger' => '#A4483C'],
                    ['display' => 'elegant', 'density' => 'spacious', 'display_stack' => '"Cormorant Garamond", "Didot", Georgia, serif', 'base_size' => 17],
                    ['radius' => 'sharp', 'card' => 'flat'],
                ),
                'brand' => ['name' => 'Lumen & Co', 'wordmark' => 'lumen/co', 'logo_url' => '/brand/lumen-co.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'centered', 'sticky' => true],
                'footer' => ['layout' => 'minimal', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Collections', 'links' => [['label' => 'Rings', 'href' => '/category/rings'], ['label' => 'Necklaces', 'href' => '/category/necklaces'], ['label' => 'Gifts', 'href' => '/category/gifts']]],
                    ['title' => 'Care', 'links' => [['label' => 'Jewelry care', 'href' => '/care'], ['label' => 'Warranty', 'href' => '/warranty'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'spotlight', 'product_detail_variant' => 'page-gallery-left', 'product_list_variant' => 'list-editorial', 'purchase_variant' => 'standard'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'collection_directory', 'product_collection', 'editorial_story', 'usp_strip', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'jewelry-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Pieces made to be kept', 'eyebrow' => 'Fine jewelry', 'cta' => ['label' => 'Explore collections', 'href' => '/collections']]],
                    ['id' => 'jewelry-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Everyday gold', 'subtitle' => 'Quiet pieces for daily wear.', 'href' => '/collection/everyday-gold', 'image' => '/media/storefront/jewelry-gold.jpg', 'cta' => ['label' => 'View collection', 'href' => '/collection/everyday-gold']],
                        ['title' => 'Gift edit', 'subtitle' => 'Thoughtful pieces ready to gift.', 'href' => '/collection/gifts', 'image' => '/media/storefront/jewelry-gifts.jpg', 'cta' => ['label' => 'Shop gifts', 'href' => '/collection/gifts']],
                    ]]],
                    ['id' => 'jewelry-collections', 'type' => 'collection_directory', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Collections']],
                    ['id' => 'jewelry-signature', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'featured', 'title' => 'Signature pieces', 'eyebrow' => 'Featured', 'limit' => 6, 'cta' => ['label' => 'See featured', 'href' => '/search?featured=1']]],
                    ['id' => 'jewelry-gifted', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'best_selling', 'title' => 'Most gifted', 'eyebrow' => 'Best sellers', 'limit' => 6, 'cta' => ['label' => 'See all', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'jewelry-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 60, 'props' => ['title' => 'Craft and provenance', 'eyebrow' => 'Atelier']],
                    ['id' => 'jewelry-usp', 'type' => 'usp_strip', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['icon' => 'document', 'title' => 'Certified materials', 'copy' => 'Metal and stone details documented for every piece.'],
                        ['icon' => 'shield', 'title' => 'Lifetime warranty', 'copy' => 'Craftsmanship covered for the life of the piece.'],
                        ['icon' => 'refresh', 'title' => '30-day returns', 'copy' => 'Unworn pieces can be returned within 30 days.'],
                    ]]],
                    ['id' => 'jewelry-faq', 'type' => 'faq', 'enabled' => true, 'order' => 80, 'props' => ['items' => [
                        ['question' => 'Are pieces certified?', 'answer' => 'Material and stone details are documented on each product page and included with your order.'],
                        ['question' => 'How do I find my ring size?', 'answer' => 'Use the ring size guide or request a sizing tool from support before ordering.'],
                        ['question' => 'What does the warranty cover?', 'answer' => 'The warranty covers manufacturing and craftsmanship defects. Wear and accidental damage are assessed case by case.'],
                        ['question' => 'Can I return a piece?', 'answer' => 'Unworn pieces in original packaging can be returned within 30 days.'],
                    ]]],
                    ['id' => 'jewelry-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 90, 'props' => []],
                ],
            ],
            'furniture' => [
                'name' => 'Furniture & Home Living',
                'description' => 'Warm furniture storefront with large imagery, delivery and assembly guidance plus showroom FAQ.',
                'preview' => '/media/storefront/previews/furniture.svg',
                'theme' => self::theme(
                    'furniture',
                    ['ink' => '#2E241C', 'muted' => '#6E5D4C', 'paper' => '#F7F1E8', 'surface' => '#FFFCF7', 'accent' => '#B5653B', 'accent_dark' => '#8E4B29', 'success' => '#4F7351', 'danger' => '#A3453A'],
                    ['display' => 'editorial', 'density' => 'comfortable', 'display_stack' => '"Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif', 'base_size' => 16],
                    ['radius' => 'soft', 'card' => 'elevated'],
                ),
                'brand' => ['name' => 'Hearth & Frame', 'wordmark' => 'hearth/frame', 'logo_url' => '/brand/hearth-frame.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'standard', 'sticky' => false],
                'footer' => ['layout' => 'mega', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Rooms', 'links' => [['label' => 'Living', 'href' => '/category/living'], ['label' => 'Bedroom', 'href' => '/category/bedroom'], ['label' => 'Dining', 'href' => '/category/dining']]],
                    ['title' => 'Service', 'links' => [['label' => 'Delivery & assembly', 'href' => '/delivery'], ['label' => 'Showroom', 'href' => '/showroom'], ['label' => 'Returns', 'href' => '/returns']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'editorial', 'product_detail_variant' => 'page-gallery-left', 'product_list_variant' => 'list-grid-wide', 'purchase_variant' => 'standard'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'category_links', 'product_collection', 'editorial_story', 'usp_strip', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'furniture-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Rooms that feel like home', 'eyebrow' => 'Furniture & living', 'cta' => ['label' => 'Shop by room', 'href' => '/collections/rooms']]],
                    ['id' => 'furniture-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Living room refresh', 'subtitle' => 'Anchors, textures and soft light.', 'href' => '/category/living', 'image' => '/media/storefront/furniture-living.jpg', 'cta' => ['label' => 'Shop living', 'href' => '/category/living']],
                        ['title' => 'Bedroom calm', 'subtitle' => 'Quiet comfort for slower mornings.', 'href' => '/category/bedroom', 'image' => '/media/storefront/furniture-bedroom.jpg', 'cta' => ['label' => 'Shop bedroom', 'href' => '/category/bedroom']],
                    ]]],
                    ['id' => 'furniture-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Shop by room']],
                    ['id' => 'furniture-bestsellers', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'best_selling', 'title' => 'Customer favourites', 'eyebrow' => 'Best sellers', 'limit' => 8, 'cta' => ['label' => 'See all favourites', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'furniture-featured', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'featured', 'title' => 'Designer picks', 'eyebrow' => 'Featured', 'limit' => 6, 'cta' => ['label' => 'See featured', 'href' => '/search?featured=1']]],
                    ['id' => 'furniture-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 60, 'props' => ['title' => 'Made for daily life', 'eyebrow' => 'Craft']],
                    ['id' => 'furniture-usp', 'type' => 'usp_strip', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['icon' => 'truck', 'title' => 'Two-person delivery', 'copy' => 'Large items delivered to the room of your choice.'],
                        ['icon' => 'calendar', 'title' => 'Assembly slots', 'copy' => 'Book assembly at checkout for eligible pieces.'],
                        ['icon' => 'ruler', 'title' => 'Dimensions listed', 'copy' => 'Measurements and material notes on every product.'],
                        ['icon' => 'refresh', 'title' => '30-day returns', 'copy' => 'Eligible items can be returned within 30 days.'],
                    ]]],
                    ['id' => 'furniture-faq', 'type' => 'faq', 'enabled' => true, 'order' => 80, 'props' => ['items' => [
                        ['question' => 'How is furniture delivered?', 'answer' => 'Large items are delivered by a two-person team. Available windows appear at checkout.'],
                        ['question' => 'Is assembly included?', 'answer' => 'Assembly can be added at checkout for eligible pieces and is shown in your order summary.'],
                        ['question' => 'How do I check it will fit?', 'answer' => 'Every product page lists dimensions. Measure doorways and lift access before ordering large pieces.'],
                        ['question' => 'Can I return large items?', 'answer' => 'Eligible items can be returned within 30 days. Collection fees may apply for large furniture.'],
                    ]]],
                    ['id' => 'furniture-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 90, 'props' => []],
                ],
            ],
            'sports' => [
                'name' => 'Sports & Performance',
                'description' => 'High-energy sports storefront with performance collections, size guidance and warranty proof.',
                'preview' => '/media/storefront/previews/sports.svg',
                'theme' => self::theme(
                    'sports',
                    ['ink' => '#0F1B2A', 'muted' => '#4E6178', 'paper' => '#F3F6FA', 'surface' => '#FFFFFF', 'accent' => '#1B6EF3', 'accent_dark' => '#1452B8', 'success' => '#21865A', 'danger' => '#C0302B'],
                    ['display' => 'modern', 'density' => 'compact', 'display_stack' => '"Barlow", "Segoe UI", Roboto, Arial, sans-serif', 'base_size' => 15],
                    ['radius' => 'sharp', 'card' => 'bordered'],
                ),
                'brand' => ['name' => 'Peak Motion', 'wordmark' => 'peak/motion', 'logo_url' => '/brand/peak-motion.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'split', 'sticky' => true],
                'footer' => ['layout' => 'mega', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Shop', 'links' => [['label' => 'Running', 'href' => '/category/running'], ['label' => 'Training', 'href' => '/category/training'], ['label' => 'Outdoor', 'href' => '/category/outdoor']]],
                    ['title' => 'Support', 'links' => [['label' => 'Size guide', 'href' => '/size-guide'], ['label' => 'Warranty', 'href' => '/warranty'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'minimal', 'product_detail_variant' => 'split-buybox', 'product_list_variant' => 'list-grid-wide', 'purchase_variant' => 'compact'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'category_links', 'product_collection', 'usp_strip', 'editorial_story', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'sports-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Built for the next rep', 'eyebrow' => 'Performance gear', 'cta' => ['label' => 'Shop performance', 'href' => '/collection/performance']]],
                    ['id' => 'sports-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Run further', 'subtitle' => 'Cushioned daily trainers and race-day shoes.', 'href' => '/collection/running', 'image' => '/media/storefront/sports-running.jpg', 'cta' => ['label' => 'Shop running', 'href' => '/collection/running']],
                        ['title' => 'Train smarter', 'subtitle' => 'Support for strength, mobility and recovery.', 'href' => '/collection/training', 'image' => '/media/storefront/sports-training.jpg', 'cta' => ['label' => 'Shop training', 'href' => '/collection/training']],
                    ]]],
                    ['id' => 'sports-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Shop by sport']],
                    ['id' => 'sports-bestsellers', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'best_selling', 'title' => 'Top performers', 'eyebrow' => 'Best sellers', 'limit' => 8, 'cta' => ['label' => 'See all top performers', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'sports-new', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'tag:new', 'title' => 'New season kit', 'eyebrow' => 'New in', 'limit' => 8, 'cta' => ['label' => 'Shop new', 'href' => '/search?tag=new']]],
                    ['id' => 'sports-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 60, 'props' => ['title' => 'Gear guides', 'eyebrow' => 'Performance']],
                    ['id' => 'sports-usp', 'type' => 'usp_strip', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['icon' => 'ruler', 'title' => 'Fit and size charts', 'copy' => 'Technical fit notes for every performance style.'],
                        ['icon' => 'shield', 'title' => 'Warranty covered', 'copy' => 'Manufacturer warranty shown before you buy.'],
                        ['icon' => 'truck', 'title' => 'Fast shipping', 'copy' => 'Tracked dispatch on in-stock performance gear.'],
                    ]]],
                    ['id' => 'sports-faq', 'type' => 'faq', 'enabled' => true, 'order' => 80, 'props' => ['items' => [
                        ['question' => 'How do I choose a size?', 'answer' => 'Use the size chart and fit notes on the product page for a performance-specific recommendation.'],
                        ['question' => 'Is gear covered by warranty?', 'answer' => 'Warranty length and coverage are shown on each product page and on your order confirmation.'],
                        ['question' => 'Can I return worn gear?', 'answer' => 'Eligible unused items can be returned within 30 days. Worn items are assessed case by case.'],
                        ['question' => 'How should I care for technical gear?', 'answer' => 'Follow the care label on each item. Care notes are also listed on the product page.'],
                    ]]],
                    ['id' => 'sports-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 90, 'props' => []],
                ],
            ],
            'pets' => [
                'name' => 'Pets & Pet Supplies',
                'description' => 'Playful pet storefront organised by species, with food subscription banners and vet FAQ.',
                'preview' => '/media/storefront/previews/pets.svg',
                'theme' => self::theme(
                    'pets',
                    ['ink' => '#173B3A', 'muted' => '#4F6E6C', 'paper' => '#F1FAF8', 'surface' => '#FFFFFF', 'accent' => '#1F9E8F', 'accent_dark' => '#16766B', 'success' => '#3B8A4F', 'danger' => '#C05A2E'],
                    ['display' => 'friendly', 'density' => 'comfortable', 'display_stack' => '"Nunito", "Segoe UI", Roboto, Arial, sans-serif', 'base_size' => 16],
                    ['radius' => 'round', 'card' => 'elevated'],
                ),
                'brand' => ['name' => 'Happy Paws', 'wordmark' => 'happy/paws', 'logo_url' => '/brand/happy-paws.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'standard', 'sticky' => false],
                'footer' => ['layout' => 'standard', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Shop by pet', 'links' => [['label' => 'Dogs', 'href' => '/category/dogs'], ['label' => 'Cats', 'href' => '/category/cats'], ['label' => 'Small pets', 'href' => '/category/small-pets']]],
                    ['title' => 'Help', 'links' => [['label' => 'Food subscription', 'href' => '/subscriptions'], ['label' => 'Vet advice', 'href' => '/vet-advice'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'editorial', 'product_detail_variant' => 'focused', 'product_list_variant' => 'list-grid-wide', 'purchase_variant' => 'compact'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'category_links', 'product_collection', 'usp_strip', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'pets-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Happier pets, easier care', 'eyebrow' => 'Pet supplies', 'cta' => ['label' => 'Shop by pet', 'href' => '/categories']]],
                    ['id' => 'pets-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Food on repeat', 'subtitle' => 'Set a delivery schedule and never run low.', 'href' => '/subscriptions', 'image' => '/media/storefront/pets-food.jpg', 'cta' => ['label' => 'Start subscription', 'href' => '/subscriptions']],
                        ['title' => 'New pet starter kit', 'subtitle' => 'The basics for a calm first week.', 'href' => '/collection/starter-kit', 'image' => '/media/storefront/pets-starter.jpg', 'cta' => ['label' => 'Shop starter kit', 'href' => '/collection/starter-kit']],
                    ]]],
                    ['id' => 'pets-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Shop by pet']],
                    ['id' => 'pets-bestsellers', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'best_selling', 'title' => 'Pet favourites', 'eyebrow' => 'Best sellers', 'limit' => 8, 'cta' => ['label' => 'See all favourites', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'pets-featured', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'featured', 'title' => 'Vet recommended', 'eyebrow' => 'Featured', 'limit' => 6, 'cta' => ['label' => 'See featured', 'href' => '/search?featured=1']]],
                    ['id' => 'pets-usp', 'type' => 'usp_strip', 'enabled' => true, 'order' => 60, 'props' => ['items' => [
                        ['icon' => 'calendar', 'title' => 'Flexible subscriptions', 'copy' => 'Skip, pause or change your delivery any time.'],
                        ['icon' => 'shield', 'title' => 'Vet reviewed', 'copy' => 'Selected ranges reviewed by veterinary professionals.'],
                        ['icon' => 'truck', 'title' => 'Fast delivery', 'copy' => 'Tracked dispatch on in-stock pet essentials.'],
                        ['icon' => 'refresh', 'title' => 'Easy returns', 'copy' => 'Unopened items can be returned within 30 days.'],
                    ]]],
                    ['id' => 'pets-faq', 'type' => 'faq', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['question' => 'How do food subscriptions work?', 'answer' => 'Choose a schedule and quantity. You can skip, pause or cancel before the next billing date.'],
                        ['question' => 'Can I get vet advice?', 'answer' => 'The vet advice page collects general guidance. For medical concerns, contact your own veterinarian.'],
                        ['question' => 'How should I store pet food?', 'answer' => 'Keep dry food sealed in a cool, dry place. Storage notes are printed on the packaging.'],
                        ['question' => 'Can I return pet products?', 'answer' => 'Unopened items can be returned within 30 days. Opened food cannot be returned for safety.'],
                    ]]],
                    ['id' => 'pets-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 80, 'props' => []],
                ],
            ],
            'baby' => [
                'name' => 'Baby & Mother Care',
                'description' => 'Gentle baby and mother storefront with safety proof, age-based sizing and certified products.',
                'preview' => '/media/storefront/previews/baby.svg',
                'theme' => self::theme(
                    'baby',
                    ['ink' => '#2C3440', 'muted' => '#64707E', 'paper' => '#F3F8FB', 'surface' => '#FFFFFF', 'accent' => '#6FA8DC', 'accent_dark' => '#4F86B8', 'success' => '#4E8A67', 'danger' => '#B4544C'],
                    ['display' => 'friendly', 'density' => 'comfortable', 'display_stack' => '"Quicksand", "Segoe UI", Roboto, Arial, sans-serif', 'base_size' => 16],
                    ['radius' => 'round', 'card' => 'elevated'],
                ),
                'brand' => ['name' => 'Little Nest', 'wordmark' => 'little/nest', 'logo_url' => '/brand/little-nest.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'centered', 'sticky' => true],
                'footer' => ['layout' => 'mega', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Shop', 'links' => [['label' => 'Baby clothing', 'href' => '/category/baby-clothing'], ['label' => 'Feeding', 'href' => '/category/feeding'], ['label' => 'Nursery', 'href' => '/category/nursery']]],
                    ['title' => 'Help', 'links' => [['label' => 'Size by age', 'href' => '/size-guide'], ['label' => 'Safety', 'href' => '/safety'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'editorial', 'product_detail_variant' => 'focused', 'product_list_variant' => 'list-editorial', 'purchase_variant' => 'standard'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'category_links', 'product_collection', 'editorial_story', 'usp_strip', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'baby-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Soft, safe and simple', 'eyebrow' => 'Baby & mother care', 'cta' => ['label' => 'Shop by age', 'href' => '/size-guide']]],
                    ['id' => 'baby-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Newborn essentials', 'subtitle' => 'The gentle basics for the first weeks.', 'href' => '/collection/newborn', 'image' => '/media/storefront/baby-newborn.jpg', 'cta' => ['label' => 'Shop newborn', 'href' => '/collection/newborn']],
                        ['title' => 'Nursery setup', 'subtitle' => 'Calm, safe pieces for sleep and play.', 'href' => '/category/nursery', 'image' => '/media/storefront/baby-nursery.jpg', 'cta' => ['label' => 'Shop nursery', 'href' => '/category/nursery']],
                    ]]],
                    ['id' => 'baby-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Shop by age']],
                    ['id' => 'baby-bestsellers', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'best_selling', 'title' => 'Parent favourites', 'eyebrow' => 'Best sellers', 'limit' => 8, 'cta' => ['label' => 'See all favourites', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'baby-certified', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'featured', 'title' => 'Safety certified', 'eyebrow' => 'Featured', 'limit' => 6, 'cta' => ['label' => 'See certified picks', 'href' => '/search?featured=1']]],
                    ['id' => 'baby-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 60, 'props' => ['title' => 'Care notes', 'eyebrow' => 'Parent guide']],
                    ['id' => 'baby-usp', 'type' => 'usp_strip', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['icon' => 'shield', 'title' => 'Safety certified', 'copy' => 'Products meet recognised safety standards where required.'],
                        ['icon' => 'ruler', 'title' => 'Size by age', 'copy' => 'Clear age and measurement guidance on every style.'],
                        ['icon' => 'refresh', 'title' => 'Gentle returns', 'copy' => 'Unused items can be returned within 30 days.'],
                    ]]],
                    ['id' => 'baby-faq', 'type' => 'faq', 'enabled' => true, 'order' => 80, 'props' => ['items' => [
                        ['question' => 'How do I choose size by month?', 'answer' => 'Use the age and measurement chart on each product. When between sizes, size up for comfort.'],
                        ['question' => 'Are products safety certified?', 'answer' => 'Safety certifications are listed on each product page where they apply.'],
                        ['question' => 'Are feeding products safe?', 'answer' => 'Feeding items include age guidance and care instructions. Follow the product label and manual.'],
                        ['question' => 'Can I return baby products?', 'answer' => 'Unused items in original packaging can be returned within 30 days.'],
                    ]]],
                    ['id' => 'baby-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 90, 'props' => []],
                ],
            ],
            'books' => [
                'name' => 'Books & Stationery',
                'description' => 'Editorial books and stationery storefront with author and topic discovery plus stock FAQ.',
                'preview' => '/media/storefront/previews/books.svg',
                'theme' => self::theme(
                    'books',
                    ['ink' => '#241E18', 'muted' => '#6B5F52', 'paper' => '#F6F1E7', 'surface' => '#FFFCF5', 'accent' => '#7A5230', 'accent_dark' => '#5C3D22', 'success' => '#4C6B45', 'danger' => '#9E4237'],
                    ['display' => 'editorial', 'density' => 'comfortable', 'display_stack' => '"Source Serif 4", Georgia, "Times New Roman", serif', 'base_size' => 16],
                    ['radius' => 'sharp', 'card' => 'flat'],
                ),
                'brand' => ['name' => 'Paper Lane', 'wordmark' => 'paper/lane', 'logo_url' => '/brand/paper-lane.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'standard', 'sticky' => false],
                'footer' => ['layout' => 'standard', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Browse', 'links' => [['label' => 'Fiction', 'href' => '/category/fiction'], ['label' => 'Non-fiction', 'href' => '/category/non-fiction'], ['label' => 'Stationery', 'href' => '/category/stationery']]],
                    ['title' => 'Help', 'links' => [['label' => 'Order status', 'href' => '/orders'], ['label' => 'Shipping', 'href' => '/shipping'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'minimal', 'product_detail_variant' => 'editorial', 'product_list_variant' => 'list-editorial', 'purchase_variant' => 'standard'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'collection_directory', 'product_collection', 'editorial_story', 'usp_strip', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'books-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Stories worth keeping', 'eyebrow' => 'Books & stationery', 'cta' => ['label' => 'Browse new releases', 'href' => '/search?tag=new']]],
                    ['id' => 'books-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Staff picks', 'subtitle' => 'Titles our booksellers keep recommending.', 'href' => '/collection/staff-picks', 'image' => '/media/storefront/books-staff.jpg', 'cta' => ['label' => 'View picks', 'href' => '/collection/staff-picks']],
                        ['title' => 'Desk essentials', 'subtitle' => 'Notebooks, pens and paper for daily work.', 'href' => '/category/stationery', 'image' => '/media/storefront/books-stationery.jpg', 'cta' => ['label' => 'Shop stationery', 'href' => '/category/stationery']],
                    ]]],
                    ['id' => 'books-topics', 'type' => 'collection_directory', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Browse by topic']],
                    ['id' => 'books-new', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'tag:new', 'title' => 'New releases', 'eyebrow' => 'New in', 'limit' => 8, 'cta' => ['label' => 'See new releases', 'href' => '/search?tag=new']]],
                    ['id' => 'books-favourites', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'best_selling', 'title' => 'Reader favourites', 'eyebrow' => 'Best sellers', 'limit' => 8, 'cta' => ['label' => 'See all favourites', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'books-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 60, 'props' => ['title' => 'Author spotlight', 'eyebrow' => 'Interviews']],
                    ['id' => 'books-usp', 'type' => 'usp_strip', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['icon' => 'list', 'title' => 'Accurate stock', 'copy' => 'Availability is shown live before you add to basket.'],
                        ['icon' => 'truck', 'title' => 'Careful packaging', 'copy' => 'Books ship protected to arrive in readable condition.'],
                        ['icon' => 'refresh', 'title' => 'Easy returns', 'copy' => 'Eligible items can be returned within 30 days.'],
                    ]]],
                    ['id' => 'books-faq', 'type' => 'faq', 'enabled' => true, 'order' => 80, 'props' => ['items' => [
                        ['question' => 'Is stock shown live?', 'answer' => 'Availability reflects current stock. If an item sells out before dispatch, support will contact you.'],
                        ['question' => 'Can I pre-order?', 'answer' => 'Pre-orders are shown with an expected date on eligible product pages.'],
                        ['question' => 'How are books shipped?', 'answer' => 'Orders ship tracked and protected. Delivery estimates are shown at checkout.'],
                        ['question' => 'Can I return a book?', 'answer' => 'Eligible items in resaleable condition can be returned within 30 days.'],
                    ]]],
                    ['id' => 'books-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 90, 'props' => []],
                ],
            ],
            'flowers' => [
                'name' => 'Flowers & Gifts',
                'description' => 'Romantic flower and gift storefront with same-day delivery, occasion edits and card messages.',
                'preview' => '/media/storefront/previews/flowers.svg',
                'theme' => self::theme(
                    'flowers',
                    ['ink' => '#3A2230', 'muted' => '#7B5E6C', 'paper' => '#FCF3F6', 'surface' => '#FFFDFE', 'accent' => '#C2557C', 'accent_dark' => '#9A3C60', 'success' => '#4F7A5B', 'danger' => '#A84444'],
                    ['display' => 'elegant', 'density' => 'spacious', 'display_stack' => '"Cormorant Garamond", Georgia, "Times New Roman", serif', 'base_size' => 17],
                    ['radius' => 'round', 'card' => 'elevated'],
                ),
                'brand' => ['name' => 'Bloom Post', 'wordmark' => 'bloom/post', 'logo_url' => '/brand/bloom-post.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'centered', 'sticky' => true],
                'footer' => ['layout' => 'minimal', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Occasions', 'links' => [['label' => 'Birthday', 'href' => '/occasions/birthday'], ['label' => 'Anniversary', 'href' => '/occasions/anniversary'], ['label' => 'Sympathy', 'href' => '/occasions/sympathy']]],
                    ['title' => 'Help', 'links' => [['label' => 'Delivery', 'href' => '/delivery'], ['label' => 'Card message', 'href' => '/card-message'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'spotlight', 'product_detail_variant' => 'page-gallery-left', 'product_list_variant' => 'list-editorial', 'purchase_variant' => 'compact'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'category_links', 'product_collection', 'editorial_story', 'usp_strip', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'flowers-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Say it with flowers', 'eyebrow' => 'Same-day delivery', 'cta' => ['label' => 'Shop bouquets', 'href' => '/category/bouquets']]],
                    ['id' => 'flowers-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Birthday bouquets', 'subtitle' => 'Bright arrangements for a happy day.', 'href' => '/occasions/birthday', 'image' => '/media/storefront/flowers-birthday.jpg', 'cta' => ['label' => 'Shop birthday', 'href' => '/occasions/birthday']],
                        ['title' => 'Just because', 'subtitle' => 'Small gestures that say a lot.', 'href' => '/collection/just-because', 'image' => '/media/storefront/flowers-just-because.jpg', 'cta' => ['label' => 'Shop gifts', 'href' => '/collection/just-because']],
                    ]]],
                    ['id' => 'flowers-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Shop by occasion']],
                    ['id' => 'flowers-gifted', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'best_selling', 'title' => 'Most gifted', 'eyebrow' => 'Best sellers', 'limit' => 8, 'cta' => ['label' => 'See all', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'flowers-seasonal', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'featured', 'title' => 'Seasonal stems', 'eyebrow' => 'Featured', 'limit' => 6, 'cta' => ['label' => 'See featured', 'href' => '/search?featured=1']]],
                    ['id' => 'flowers-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 60, 'props' => ['title' => 'Flower care tips', 'eyebrow' => 'Journal']],
                    ['id' => 'flowers-usp', 'type' => 'usp_strip', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['icon' => 'truck', 'title' => 'Same-day delivery', 'copy' => 'Order before the daily cut-off for same-day dispatch.'],
                        ['icon' => 'calendar', 'title' => 'Date scheduling', 'copy' => 'Choose a delivery date up to weeks ahead.'],
                        ['icon' => 'document', 'title' => 'Free card message', 'copy' => 'Add a handwritten-style note at no extra cost.'],
                        ['icon' => 'leaf', 'title' => 'Fresh guarantee', 'copy' => 'If flowers arrive damaged, we make it right.'],
                    ]]],
                    ['id' => 'flowers-faq', 'type' => 'faq', 'enabled' => true, 'order' => 80, 'props' => ['items' => [
                        ['question' => 'What is the same-day cut-off?', 'answer' => 'Cut-off times depend on the delivery area and are shown at checkout.'],
                        ['question' => 'Can I include a card message?', 'answer' => 'Yes. Add your message during checkout and it is included at no extra cost.'],
                        ['question' => 'Can I track my delivery?', 'answer' => 'Tracking updates are sent when the bouquet leaves the florist.'],
                        ['question' => 'What if the flowers arrive damaged?', 'answer' => 'Contact support with a photo within 24 hours and we will arrange a replacement or refund.'],
                    ]]],
                    ['id' => 'flowers-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 90, 'props' => []],
                ],
            ],
            'supplements' => [
                'name' => 'Supplements & Wellness',
                'description' => 'Trust-led supplement storefront with dosage guidance, certification proof and subscription options.',
                'preview' => '/media/storefront/previews/supplements.svg',
                'theme' => self::theme(
                    'supplements',
                    ['ink' => '#14221E', 'muted' => '#4F625B', 'paper' => '#F2F8F5', 'surface' => '#FFFFFF', 'accent' => '#2F7D5B', 'accent_dark' => '#215E44', 'success' => '#2E7D4F', 'danger' => '#B23B3B'],
                    ['display' => 'clean', 'density' => 'comfortable', 'display_stack' => '"Source Sans 3", "Segoe UI", Roboto, Arial, sans-serif', 'base_size' => 15],
                    ['radius' => 'soft', 'card' => 'bordered'],
                ),
                'brand' => ['name' => 'Wellform', 'wordmark' => 'well/form', 'logo_url' => '/brand/wellform.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'minimal', 'sticky' => true],
                'footer' => ['layout' => 'standard', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Shop', 'links' => [['label' => 'Vitamins', 'href' => '/category/vitamins'], ['label' => 'Protein', 'href' => '/category/protein'], ['label' => 'Wellness', 'href' => '/category/wellness']]],
                    ['title' => 'Trust', 'links' => [['label' => 'Lab reports', 'href' => '/lab-reports'], ['label' => 'Dosage guide', 'href' => '/dosage'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'minimal', 'product_detail_variant' => 'split-buybox', 'product_list_variant' => 'list-grid-wide', 'purchase_variant' => 'standard'],
                'preferred_sections' => ['campaign_hero', 'usp_strip', 'banner_grid', 'category_links', 'product_collection', 'editorial_story', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'supplements-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Wellness you can verify', 'eyebrow' => 'Supplements', 'cta' => ['label' => 'Shop supplements', 'href' => '/category/vitamins']]],
                    ['id' => 'supplements-trust', 'type' => 'usp_strip', 'enabled' => true, 'order' => 20, 'props' => ['items' => [
                        ['icon' => 'document', 'title' => 'Third-party tested', 'copy' => 'Batch testing results are linked from eligible products.'],
                        ['icon' => 'shield', 'title' => 'Clear dosage', 'copy' => 'Serving size and daily guidance shown up front.'],
                        ['icon' => 'calendar', 'title' => 'Subscribe and save', 'copy' => 'Flexible recurring delivery on everyday essentials.'],
                        ['icon' => 'check', 'title' => 'Genuine products', 'copy' => 'Sourced through authorised supply channels.'],
                    ]]],
                    ['id' => 'supplements-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 30, 'props' => ['banners' => [
                        ['title' => 'Daily essentials', 'subtitle' => 'Simple foundations for everyday nutrition.', 'href' => '/collection/daily', 'image' => '/media/storefront/supplements-daily.jpg', 'cta' => ['label' => 'Shop daily', 'href' => '/collection/daily']],
                        ['title' => 'Subscribe and save', 'subtitle' => 'Set a schedule and skip deliveries any time.', 'href' => '/subscriptions', 'image' => '/media/storefront/supplements-subscription.jpg', 'cta' => ['label' => 'Start subscription', 'href' => '/subscriptions']],
                    ]]],
                    ['id' => 'supplements-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 40, 'props' => ['title' => 'Shop by goal']],
                    ['id' => 'supplements-reviewed', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'best_selling', 'title' => 'Best reviewed', 'eyebrow' => 'Best sellers', 'limit' => 8, 'cta' => ['label' => 'See all', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'supplements-new', 'type' => 'product_collection', 'enabled' => true, 'order' => 60, 'props' => ['source' => 'featured', 'title' => 'New arrivals', 'eyebrow' => 'Featured', 'limit' => 6, 'cta' => ['label' => 'See new', 'href' => '/search?featured=1']]],
                    ['id' => 'supplements-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 70, 'props' => ['title' => 'Dosage and ingredients', 'eyebrow' => 'Guides']],
                    ['id' => 'supplements-faq', 'type' => 'faq', 'enabled' => true, 'order' => 80, 'props' => ['items' => [
                        ['question' => 'How do I know the right dosage?', 'answer' => 'Follow the label and the dosage guide. Consult a healthcare professional for personal advice.'],
                        ['question' => 'Are products lab tested?', 'answer' => 'Batch testing is documented for eligible products and linked from the product page.'],
                        ['question' => 'Can I pause a subscription?', 'answer' => 'Yes. Skip, pause or cancel before the next billing date from your account.'],
                        ['question' => 'Can I return supplements?', 'answer' => 'For safety, opened supplements cannot be returned unless faulty or incorrect.'],
                    ]]],
                    ['id' => 'supplements-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 90, 'props' => []],
                ],
            ],
            'auto' => [
                'name' => 'Auto Parts & Accessories',
                'description' => 'Technical auto parts storefront with fitment guidance, specification proof and garage FAQ.',
                'preview' => '/media/storefront/previews/auto.svg',
                'theme' => self::theme(
                    'auto',
                    ['ink' => '#15181C', 'muted' => '#59616B', 'paper' => '#F3F5F7', 'surface' => '#FFFFFF', 'accent' => '#C47A1E', 'accent_dark' => '#985C13', 'success' => '#2F7A4F', 'danger' => '#B02D25'],
                    ['display' => 'technical', 'density' => 'compact', 'display_stack' => '"Barlow", "Segoe UI", Roboto, Arial, sans-serif', 'base_size' => 15],
                    ['radius' => 'sharp', 'card' => 'bordered'],
                ),
                'brand' => ['name' => 'Torque Line', 'wordmark' => 'torque/line', 'logo_url' => '/brand/torque-line.svg', 'favicon_url' => null],
                'header' => ['announcement' => true, 'layout' => 'split', 'sticky' => true],
                'footer' => ['layout' => 'mega', 'show_help' => true, 'show_policies' => true, 'columns' => [
                    ['title' => 'Shop', 'links' => [['label' => 'Engine', 'href' => '/category/engine'], ['label' => 'Brakes', 'href' => '/category/brakes'], ['label' => 'Accessories', 'href' => '/category/accessories']]],
                    ['title' => 'Support', 'links' => [['label' => 'Fitment guide', 'href' => '/fitment'], ['label' => 'Warranty', 'href' => '/warranty'], ['label' => 'Contact', 'href' => '/contact']]],
                ]],
                'policies' => ['shipping_url' => '/shipping', 'returns_url' => '/returns', 'support_url' => '/contact'],
                'purchase' => ['quote_mode' => 'auto'],
                'presentation' => ['product_card_variant' => 'minimal', 'product_detail_variant' => 'split-buybox', 'product_list_variant' => 'list-grid-wide', 'purchase_variant' => 'compact'],
                'preferred_sections' => ['campaign_hero', 'banner_grid', 'category_links', 'product_collection', 'usp_strip', 'editorial_story', 'faq', 'service_proof'],
                'sections' => [
                    ['id' => 'auto-hero', 'type' => 'campaign_hero', 'enabled' => true, 'order' => 10, 'props' => ['title' => 'Parts that fit right, first time', 'eyebrow' => 'Auto parts & accessories', 'cta' => ['label' => 'Check fitment', 'href' => '/fitment']]],
                    ['id' => 'auto-banners', 'type' => 'banner_grid', 'enabled' => true, 'order' => 20, 'props' => ['banners' => [
                        ['title' => 'Brake service kit', 'subtitle' => 'Pads, discs and fluids matched to your vehicle.', 'href' => '/collection/brakes', 'image' => '/media/storefront/auto-brakes.jpg', 'cta' => ['label' => 'Shop brake kits', 'href' => '/collection/brakes']],
                        ['title' => 'Workshop essentials', 'subtitle' => 'Tools and accessories for the garage.', 'href' => '/category/accessories', 'image' => '/media/storefront/auto-accessories.jpg', 'cta' => ['label' => 'Shop accessories', 'href' => '/category/accessories']],
                    ]]],
                    ['id' => 'auto-categories', 'type' => 'category_links', 'enabled' => true, 'order' => 30, 'props' => ['title' => 'Shop by vehicle system']],
                    ['id' => 'auto-bestsellers', 'type' => 'product_collection', 'enabled' => true, 'order' => 40, 'props' => ['source' => 'best_selling', 'title' => 'Top selling parts', 'eyebrow' => 'Best sellers', 'limit' => 8, 'cta' => ['label' => 'See all bestsellers', 'href' => '/search?sort=best_selling']]],
                    ['id' => 'auto-new', 'type' => 'product_collection', 'enabled' => true, 'order' => 50, 'props' => ['source' => 'tag:new', 'title' => 'New arrivals', 'eyebrow' => 'New in', 'limit' => 8, 'cta' => ['label' => 'Shop new', 'href' => '/search?tag=new']]],
                    ['id' => 'auto-story', 'type' => 'editorial_story', 'enabled' => true, 'order' => 60, 'props' => ['title' => 'Fitment and specs', 'eyebrow' => 'Technical guides']],
                    ['id' => 'auto-usp', 'type' => 'usp_strip', 'enabled' => true, 'order' => 70, 'props' => ['items' => [
                        ['icon' => 'list', 'title' => 'Fitment checker', 'copy' => 'Confirm compatibility by vehicle before you buy.'],
                        ['icon' => 'shield', 'title' => 'Warranty coverage', 'copy' => 'Warranty terms shown on each eligible part.'],
                        ['icon' => 'tool', 'title' => 'Spec sheets', 'copy' => 'Dimensions, materials and torque notes listed.'],
                        ['icon' => 'truck', 'title' => 'Fast dispatch', 'copy' => 'Tracked dispatch on in-stock parts.'],
                    ]]],
                    ['id' => 'auto-faq', 'type' => 'faq', 'enabled' => true, 'order' => 80, 'props' => ['items' => [
                        ['question' => 'How do I check fitment?', 'answer' => 'Use the fitment checker with your vehicle details. Compatibility is confirmed before checkout.'],
                        ['question' => 'Is there a warranty?', 'answer' => 'Eligible parts include manufacturer warranty. Terms are shown on the product page.'],
                        ['question' => 'Can I return fitted parts?', 'answer' => 'Unused parts in original packaging can be returned within 30 days. Fitted parts are assessed case by case.'],
                        ['question' => 'Can I get garage support?', 'answer' => 'The fitment guide covers common questions. For specific repairs, consult a qualified mechanic.'],
                    ]]],
                    ['id' => 'auto-proof', 'type' => 'service_proof', 'enabled' => true, 'order' => 90, 'props' => []],
                ],
            ],
        ];

        foreach (self::archetypes() as $key => $archetype) {
            $sections = $presets[$key]['sections'] ?? null;
            if (!is_array($sections)) {
                continue;
            }
            $presets[$key]['sections'] = self::orderSections($archetype, $sections);
        }

        return $presets;
    }

    public static function archetype(string $key): ?string
    {
        return self::archetypes()[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function archetypes(): array
    {
        return [
            'beauty' => 'editorial',
            'fashion' => 'luxury',
            'cosmetics' => 'editorial',
            'jewelry' => 'luxury',
            'flowers' => 'luxury',
            'books' => 'luxury',
            'pharmacy' => 'clinical',
            'supplements' => 'clinical',
            'baby' => 'clinical',
            'pets' => 'clinical',
            'electronics' => 'technical',
            'b2b' => 'technical',
            'furniture' => 'technical',
            'auto' => 'technical',
            'fnb' => 'lifestyle',
            'grocery' => 'lifestyle',
            'sports' => 'lifestyle',
            'footwear' => 'lifestyle',
        ];
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @return list<array<string, mixed>>
     */
    private static function orderSections(string $archetype, array $sections): array
    {
        $recipes = [
            'editorial' => ['campaign_hero' => 10, 'editorial_story' => 20, 'collection_directory' => 25, 'product_collection' => 40, 'banner_grid' => 50, 'category_links' => 60, 'usp_strip' => 70, 'trust_strip' => 65, 'faq' => 80, 'service_proof' => 90],
            'luxury' => ['campaign_hero' => 10, 'collection_directory' => 20, 'editorial_story' => 30, 'product_collection' => 40, 'banner_grid' => 50, 'usp_strip' => 60, 'category_links' => 65, 'trust_strip' => 62, 'faq' => 70, 'service_proof' => 80],
            'clinical' => ['campaign_hero' => 10, 'trust_strip' => 15, 'usp_strip' => 20, 'category_links' => 30, 'banner_grid' => 35, 'product_collection' => 40, 'editorial_story' => 50, 'faq' => 60, 'service_proof' => 70],
            'technical' => ['campaign_hero' => 10, 'trust_strip' => 20, 'category_links' => 30, 'product_collection' => 40, 'banner_grid' => 50, 'usp_strip' => 60, 'editorial_story' => 70, 'faq' => 80, 'service_proof' => 90],
            'lifestyle' => ['campaign_hero' => 10, 'category_links' => 20, 'banner_grid' => 30, 'product_collection' => 40, 'editorial_story' => 50, 'usp_strip' => 60, 'trust_strip' => 55, 'faq' => 70, 'service_proof' => 80],
        ];
        $priorities = $recipes[$archetype] ?? $recipes['editorial'];

        /** @var list<array{section: array<string, mixed>, index: int, priority: int}> $indexed */
        $indexed = [];
        foreach ($sections as $index => $section) {
            $type = is_string($section['type'] ?? null) ? $section['type'] : '';
            $indexed[] = ['section' => $section, 'index' => $index, 'priority' => $priorities[$type] ?? 65];
        }
        usort($indexed, static fn (array $a, array $b): int => [$a['priority'], $a['index']] <=> [$b['priority'], $b['index']]);

        $ordered = [];
        $order = 10;
        foreach ($indexed as $entry) {
            $section = $entry['section'];
            $section['order'] = $order;
            $ordered[] = $section;
            $order += 10;
        }

        return $ordered;
    }

    /**
     * @param array<string, string> $colors
     * @param array<string, mixed> $typography
     * @param array<string, string> $shape
     * @return array{key: string, extends: string, version: string, schema_version: int, tokens: array<string, mixed>}
     */
    private static function theme(string $key, array $colors, array $typography, array $shape): array
    {
        return [
            'key' => $key,
            'extends' => 'general',
            'version' => '1.0.0',
            'schema_version' => 1,
            'tokens' => [
                'colors' => $colors,
                'typography' => array_merge([
                    'body' => 'system',
                    'body_stack' => '"Inter", -apple-system, "Segoe UI", Roboto, Arial, sans-serif',
                ], $typography),
                'shape' => $shape,
            ],
        ];
    }
}
