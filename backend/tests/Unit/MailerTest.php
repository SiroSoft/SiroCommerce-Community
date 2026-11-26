<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Mailer;
use Siro\Core\Mail\MailProvider;
use Tests\TestCase;

final class MailerTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['MAIL_SMTP_HOST', 'MAIL_SMTP_PORT', 'MAIL_SMTP_ENCRYPTION', 'MAIL_SMTP_USER', 'MAIL_SMTP_PASS', 'MAIL_FROM', 'MAIL_FROM_NAME', 'MAIL_SMTP_TIMEOUT', 'MAIL_PROVIDER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME'] as $key) {
            putenv($key);
            unset($_ENV[$key]);
        }
        Mailer::reset();
        \Siro\Core\Mail::reset();
        parent::tearDown();
    }

    private function configure(array $vars): void
    {
        foreach ($vars as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }

    public function testStaysSilentWhenUnconfigured(): void
    {
        self::assertFalse(Mailer::isConfigured());
        self::assertFalse(Mailer::send('buyer@example.test', 'Hi', 'body'));
    }

    public function testRejectsInvalidRecipientWithoutNetwork(): void
    {
        $this->configure(['MAIL_SMTP_HOST' => 'smtp.example.test', 'MAIL_FROM' => 'shop@example.test']);
        self::assertTrue(Mailer::isConfigured());
        self::assertFalse(Mailer::send('not-an-email', 'Hi', 'body'));
    }

    public function testUnreachableServerFailsClosedWithoutThrowing(): void
    {
        $this->configure(['MAIL_SMTP_HOST' => '127.0.0.1', 'MAIL_SMTP_PORT' => '9', 'MAIL_SMTP_ENCRYPTION' => 'none', 'MAIL_FROM' => 'shop@example.test', 'MAIL_SMTP_TIMEOUT' => '2']);
        self::assertFalse(Mailer::send('buyer@example.test', 'Hi', 'body'));
    }

    public function testDelegatesToCoreMailWithLegacyEnvBridged(): void
    {
        // Default path goes through Siro\Core\Mail::trySend(): with the core
        // faked, no socket is touched and the legacy MAIL_SMTP_* names are
        // bridged to the MAIL_* names the core provider reads.
        $this->configure(['MAIL_SMTP_HOST' => 'smtp.example.test', 'MAIL_SMTP_PORT' => '587', 'MAIL_FROM' => 'shop@example.test']);
        \Siro\Core\Mail::fake();
        self::assertTrue(Mailer::send('buyer@example.test', 'Hi', 'body'));
        \Siro\Core\Mail::assertSentTo('buyer@example.test');
        self::assertSame('smtp.example.test', $_ENV['MAIL_HOST'] ?? null);
        self::assertSame('shop@example.test', $_ENV['MAIL_FROM_ADDRESS'] ?? null);
    }

    public function testHtmlBodyWinsOverTextThroughCore(): void
    {
        $this->configure(['MAIL_SMTP_HOST' => 'smtp.example.test', 'MAIL_FROM' => 'shop@example.test']);
        \Siro\Core\Mail::fake();
        self::assertTrue(Mailer::send('buyer@example.test', 'Hi', 'text', '<p>html</p>'));
        $mails = \Siro\Core\Mail::getFakedMails();
        self::assertCount(1, $mails);
        self::assertSame('<p>html</p>', $mails[0]['body']);
    }

    public function testExplicitCoreEnvWinsOverLegacyBridge(): void
    {
        $this->configure(['MAIL_SMTP_HOST' => 'smtp.legacy.test', 'MAIL_HOST' => 'smtp.core.test', 'MAIL_FROM' => 'shop@example.test', 'MAIL_FROM_ADDRESS' => 'core@example.test']);
        \Siro\Core\Mail::fake();
        self::assertTrue(Mailer::send('buyer@example.test', 'Hi', 'body'));
        self::assertSame('smtp.core.test', $_ENV['MAIL_HOST'] ?? null);
    }

    public function testExplicitSilenceViaCoreProviderName(): void
    {
        $this->configure(['MAIL_SMTP_HOST' => 'smtp.example.test', 'MAIL_FROM' => 'shop@example.test', 'MAIL_PROVIDER' => 'null']);
        self::assertFalse(Mailer::isConfigured());
        self::assertFalse(Mailer::send('buyer@example.test', 'Hi', 'body'));
    }

    public function testProviderOverrideIsUsedAndFailSafe(): void
    {
        $provider = new class() implements MailProvider {
            /** @var list<array<string, mixed>> */
            public array $sent = [];
            /** @param array<string, mixed> $mail */
            public function send(array $mail): bool
            {
                $this->sent[] = $mail;
                return true;
            }
        };
        Mailer::useProvider($provider);
        self::assertTrue(Mailer::isConfigured());
        self::assertTrue(Mailer::send('buyer@example.test', 'Hi', 'body'));
        self::assertCount(1, $provider->sent);
        self::assertSame('buyer@example.test', $provider->sent[0]['to']);
        $failing = new class() implements MailProvider {
            /** @param array<string, mixed> $mail */
            public function send(array $mail): bool
            {
                throw new \RuntimeException('transport down');
            }
        };
        Mailer::useProvider($failing);
        self::assertFalse(Mailer::send('buyer@example.test', 'Hi', 'body'));
    }
}
