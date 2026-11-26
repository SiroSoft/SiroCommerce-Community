<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Siro\Core\Lang;

/**
 * Full i18n gate (spec: 5 locales en/vi/ja/de/zh, fallback en).
 * Requires sirosoft/core autoloadable (composer install).
 */
final class LangTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!class_exists(Lang::class)) {
            self::markTestSkipped('sirosoft/core not installed (composer install)');
        }
        Lang::boot(dirname(__DIR__, 2));
        Lang::setFallbackLocale('en');
    }

    public function testCommerceKeysAcrossLocales(): void
    {
        $expect = [
            'en' => 'Products list',
            'vi' => 'Danh sách sản phẩm',
            'ja' => '商品一覧',
            'de' => 'Produktliste',
            'zh' => '商品列表',
        ];
        foreach ($expect as $locale => $text) {
            Lang::setLocale($locale);
            $this->assertSame($text, Lang::get('commerce.product.list'));
        }
    }

    public function testPlaceholders(): void
    {
        Lang::setLocale('vi');
        $this->assertSame(
            'Thanh toán thất bại: timeout',
            Lang::get('commerce.checkout.failed', ['reason' => 'timeout'])
        );
    }

    public function testUnknownLocaleFallsBackToEn(): void
    {
        Lang::setLocale('fr');
        $this->assertSame('Products list', Lang::get('commerce.product.list'));
    }
}
