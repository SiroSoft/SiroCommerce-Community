<?php
declare(strict_types=1);
namespace App\Modules\Analytics\Domain;
final readonly class FunnelResult
{
    /** @var array<string, int> */ public array $counts;
    /** @var array<string, float> */ public array $rates;
    /** @var array<int, string> */ public array $anomalies;
    /**
     * @param array<string, int> $counts
     * @param array<string, float> $rates
     * @param array<int, string> $anomalies
     */
    public function __construct(array $counts, array $rates, array $anomalies) { $this->counts = $counts; $this->rates = $rates; $this->anomalies = $anomalies; }
    /** @return array{counts: array<string, int>, rates: array<string, float>, anomalies: array<int, string>} */
    public function toArray(): array { return ['counts' => $this->counts, 'rates' => $this->rates, 'anomalies' => $this->anomalies]; }
}
