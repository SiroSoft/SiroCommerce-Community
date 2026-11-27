<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Mail\Application\MailTemplateRegistry;
use App\Support\Mailer;
use PHPUnit\Framework\TestCase;
use Siro\Core\Mail\MailProvider;

/** Batch 1 Platform §2.4: mail template registry. */
final class MailTemplateRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        MailTemplateRegistry::reset();
        Mailer::reset();
    }

    protected function tearDown(): void
    {
        MailTemplateRegistry::reset();
        Mailer::reset();
        parent::tearDown();
    }

    public function testDefaultsBindCartAbandonedToAbandonedCart(): void
    {
        self::assertSame('abandoned_cart', MailTemplateRegistry::templateFor('cart.abandoned'));
        self::assertSame(['customer_name', 'cart_url', 'lines', 'coupon_code'], MailTemplateRegistry::variablesFor('abandoned_cart'));
        self::assertSame(['customer_name', 'coupon_code'], MailTemplateRegistry::variablesFor('welcome'));
        self::assertNull(MailTemplateRegistry::templateFor('order.placed'));
    }

    public function testRegisterBindsEventToTemplate(): void
    {
        MailTemplateRegistry::register('email_capture.subscribed', 'welcome', ['customer_name', 'coupon_code']);
        self::assertSame('welcome', MailTemplateRegistry::templateFor('email_capture.subscribed'));
        self::assertSame(['customer_name', 'coupon_code'], MailTemplateRegistry::all()['email_capture.subscribed']['variables']);
    }

    public function testRegisterRejectsUnknownTemplateAndBadNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailTemplateRegistry::register('cart.abandoned', 'no_such_template', []);
    }

    public function testRegisterRejectsInvalidEventName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailTemplateRegistry::register('Bad Event!', 'welcome', []);
    }

    public function testRenderAbandonedCartIncludesLinesUrlAndCoupon(): void
    {
        $rendered = MailTemplateRegistry::render('abandoned_cart', [
            'customer_name' => 'Lan',
            'cart_url' => 'https://shop.test/cart/recover/abc',
            'lines' => [['name' => 'Silk dress', 'qty' => 2], ['name' => 'Scarf', 'qty' => 1]],
            'coupon_code' => 'COME10',
        ]);
        self::assertSame('Your cart is waiting for you', $rendered['subject']);
        self::assertStringContainsString('Hi Lan', $rendered['body']);
        self::assertStringContainsString('Silk dress x 2', $rendered['body']);
        self::assertStringContainsString('Scarf', $rendered['body']);
        self::assertStringContainsString('https://shop.test/cart/recover/abc', $rendered['body']);
        self::assertStringContainsString('COME10', $rendered['body']);
    }

    public function testRenderAbandonedCartOmitsCouponLineWhenNoCoupon(): void
    {
        $rendered = MailTemplateRegistry::render('abandoned_cart', [
            'customer_name' => 'Lan', 'cart_url' => 'https://shop.test/cart/recover/abc', 'lines' => [],
        ]);
        self::assertStringNotContainsString('coupon', strtolower($rendered['body']));
    }

    public function testRenderWelcomeIncludesCoupon(): void
    {
        $rendered = MailTemplateRegistry::render('welcome', ['customer_name' => 'Minh', 'coupon_code' => 'HELLO15']);
        self::assertSame('Welcome to SiroCommerce', $rendered['subject']);
        self::assertStringContainsString('Hi Minh', $rendered['body']);
        self::assertStringContainsString('HELLO15', $rendered['body']);
    }

    public function testRenderUnknownTemplateThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailTemplateRegistry::render('nope', []);
    }

    public function testSendReturnsFalseWithoutBinding(): void
    {
        self::assertFalse(MailTemplateRegistry::send('order.placed', 'a@test.local', []));
    }

    public function testSendDeliversRenderedTemplateTextOnly(): void
    {
        $sent = [];
        Mailer::useProvider(new class($sent) implements MailProvider {
            /** @var list<array<string, mixed>> */
            public array $sent;
            /** @param list<array<string, mixed>> $sent */
            public function __construct(array &$sent) { $this->sent = &$sent; }
            /** @param array<string, mixed> $mail */
            public function send(array $mail): bool { $this->sent[] = $mail; return true; }
        });
        self::assertTrue(MailTemplateRegistry::send('cart.abandoned', 'lan@test.local', [
            'customer_name' => 'Lan', 'cart_url' => 'https://shop.test/cart/recover/abc',
            'lines' => [['name' => 'Silk dress', 'qty' => 1]], 'coupon_code' => 'COME10',
        ]));
        self::assertCount(1, $sent);
        self::assertSame('lan@test.local', $sent[0]['to'] ?? null);
        self::assertSame('Your cart is waiting for you', $sent[0]['subject'] ?? null);
        self::assertStringContainsString('Silk dress', (string) ($sent[0]['body'] ?? ''));
        self::assertStringContainsString('COME10', (string) ($sent[0]['body'] ?? ''));
    }
}
