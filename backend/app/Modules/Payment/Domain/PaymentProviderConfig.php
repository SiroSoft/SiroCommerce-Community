<?php

declare(strict_types=1);

namespace App\Modules\Payment\Domain;

final readonly class PaymentProviderConfig
{
    /** @param array<string, string> $credentials */
    public function __construct(
        public string $provider,
        public string $mode,
        public string $baseUrl,
        public array $credentials,
    ) {
        if (preg_match('/\A[a-z0-9_]+\z/', $this->provider) !== 1) {
            throw new \InvalidArgumentException('Payment provider ID must be canonical');
        }
        if (!in_array($this->mode, ['sandbox', 'production'], true)) {
            throw new \InvalidArgumentException('Payment mode must be sandbox or production');
        }
        if ($this->baseUrl === '' || filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false || !str_starts_with($this->baseUrl, 'https://')) {
            throw new \InvalidArgumentException('Payment provider base URL must be HTTPS');
        }
    }

    public function credential(string $name): string
    {
        $value = $this->credentials[$name] ?? '';
        if (trim($value) === '' || preg_match('/[\r\n]/', $value) === 1) {
            throw new \RuntimeException('Payment provider is not configured: ' . $this->provider . '.' . $name);
        }
        return trim($value);
    }
}
