<?php

declare(strict_types=1);

use Siro\Core\Database;

/**
 * English-first catalog slugs. Fresh installs seed English directly
 * (B2cCatalogSeeder); this migrates databases seeded with Vietnamese slugs.
 * Old URLs keep working via permanent redirects in the storefront.
 */
return new class {
    private const MAP = [
        'thoi-trang' => 'apparel',
        'nha-cua-doi-song' => 'home-living',
        'cham-soc-ca-nhan' => 'personal-care',
        'do-uong-thuc-pham' => 'food-beverage',
        'ao-so-mi-linen' => 'linen-mandarin-shirt',
        'ao-thun-cot-lon-organic' => 'organic-cotton-crew-tee',
        'quan-jean-indigo-organic' => 'rigid-indigo-denim-trousers',
        'ao-khoac-wool-melange' => 'melange-wool-overshirt',
        'vay-linen-khoa' => 'linen-tie-front-blouse',
        'mu-lua-rsom' => 'packable-rope-sun-hat',
        'tui-vai-canvas-tote' => 'structured-canvas-tote',
        'tui-deu-cong-con-lining' => 'lined-leather-utility-sling',
        'nem-cao-su-thien-nhien' => 'natural-latex-mattress',
        'binh-giu-nhiet-inox' => 'stainless-steel-flask-500ml',
        'chen-ceramic-handmade' => 'hand-thrown-stoneware-bowl',
        'chan-linen-cotton' => 'stonewashed-linen-bedding-set',
        'den-sau-rubber-fsc' => 'fsc-oak-desk-tray',
        'lo-san-tho-gom' => 'speckled-stoneware-serving-jug',
        'tham-wool-lai' => 'brushed-wool-throw-blanket',
        'sua-rua-mat-diu-nhe' => 'gentle-green-tea-facial-cleanser',
        'kem-chong-nang-spf50' => 'broad-spectrum-spf50-sunscreen',
        'dau-duong-tam-sa' => 'calming-rosehip-body-wash',
        'kem-tay-am-shea' => 'shea-repair-hand-cream',
        'mui-loc-thien-nhien' => 'refillable-bamboo-toothbrush-set',
        'ca-phe-rang-moc-arabica' => 'single-origin-arabica-coffee',
        'tra-olong-hoa-lai' => 'jasmine-oolong-tea',
        'matcha-ceremony' => 'ceremonial-grade-matcha',
        'mat-hong-sen-kho' => 'dried-lotus-seed-tea',
        'socola-don-dau-viet-nam' => 'single-origin-cacao-drinking-chocolate',
        'duong-mat-ong-rong' => 'wildflower-honey-jar',
        'bo-qua-tang-buoi-sang' => 'morning-ritual-gift-set',
        'huong-dan-pha-che' => 'home-brew-guide-ebook',
        'bo-khoi-dau-linen' => 'linen-starter-set',
        'dich-vu-goi-qua' => 'gift-wrapping-service',
    ];

    public function up(): void
    {
        // Scoped to B2C seed rows only: merchant catalogs are never renamed.
        $pdo = Database::connection();
        $slug = $pdo->prepare("UPDATE categories SET slug = ? WHERE slug = ? AND id LIKE 'b2c-cat-%'");
        $product = $pdo->prepare("UPDATE products SET slug = ? WHERE slug = ? AND (id LIKE 'b2c-prod-%' OR id LIKE 'b2c-prod-vnd-%')");
        $image = $pdo->prepare("UPDATE product_images SET url = REPLACE(url, ?, ?) WHERE product_id LIKE 'b2c-prod-%'");
        foreach (self::MAP as $old => $new) {
            $slug->execute([$new, $old]);
            $product->execute([$new, $old]);
            $image->execute(['/catalog/b2c/' . $old . '-', '/catalog/b2c/' . $new . '-']);
        }
    }

    public function down(): void
    {
        $pdo = Database::connection();
        $slug = $pdo->prepare("UPDATE categories SET slug = ? WHERE slug = ? AND id LIKE 'b2c-cat-%'");
        $product = $pdo->prepare("UPDATE products SET slug = ? WHERE slug = ? AND (id LIKE 'b2c-prod-%' OR id LIKE 'b2c-prod-vnd-%')");
        $image = $pdo->prepare("UPDATE product_images SET url = REPLACE(url, ?, ?) WHERE product_id LIKE 'b2c-prod-%'");
        foreach (self::MAP as $old => $new) {
            $slug->execute([$old, $new]);
            $product->execute([$old, $new]);
            $image->execute(['/catalog/b2c/' . $new . '-', '/catalog/b2c/' . $old . '-']);
        }
    }
};
