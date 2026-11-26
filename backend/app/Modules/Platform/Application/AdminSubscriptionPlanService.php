<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Models\ProductSubscriptionPlan;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\Arr;
use App\Support\Ids;
use Siro\Core\Audit;

/** Operator CRUD for recurring plan definitions. */
final class AdminSubscriptionPlanService
{
    /** @return array<int, array<string, mixed>> */
    public function list(string $state = ''): array
    {
        $state = strtolower(trim($state));
        if ($state !== '' && !in_array($state, ['active', 'disabled'], true)) {
            throw new \InvalidArgumentException('state must be active or disabled');
        }
        $query = SubscriptionPlan::query();
        if ($state !== '') $query->where('state', $state);
        $rows = [];
        foreach ($query->orderBy('name', 'asc')->orderBy('id', 'asc')->get() as $plan) {
            $rows[] = $this->row(Arr::row($plan));
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $payload = $this->normalize($data);
        $payload['id'] = Ids::new();
        $payload['created_at'] = date('Y-m-d H:i:s');
        $payload['updated_at'] = $payload['created_at'];
        SubscriptionPlan::create($payload);
        Audit::log('commerce.subscription_plan.created', ['plan_id' => $payload['id'], 'interval' => $payload['interval']]);
        return $this->row($payload);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(string $id, array $data): ?array
    {
        $model = SubscriptionPlan::find($id);
        if ($model === null) return null;
        $existing = Arr::row($model);
        $merged = array_replace($existing, $data);
        $payload = $this->normalize($merged);
        $payload['updated_at'] = date('Y-m-d H:i:s');
        foreach ($payload as $key => $value) $model->setAttribute($key, $value);
        $model->save();
        Audit::log('commerce.subscription_plan.updated', ['plan_id' => $id, 'fields' => array_keys($data)]);
        return $this->row(array_replace($existing, $payload));
    }

    public function delete(string $id): bool
    {
        $model = SubscriptionPlan::find($id);
        if ($model === null) return false;
        if ((int) Subscription::query()->where('plan_id', $id)->count() > 0) {
            throw new \RuntimeException('Subscription plan is still referenced by subscriptions');
        }
        ProductSubscriptionPlan::query()->where('plan_id', $id)->delete();
        $model->delete();
        Audit::log('commerce.subscription_plan.deleted', ['plan_id' => $id]);
        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $name = trim(Arr::str($data, 'name'));
        if ($name === '' || mb_strlen($name) > 128) throw new \InvalidArgumentException('name is required and must be at most 128 characters');

        $amount = $data['amount_minor'] ?? null;
        if (is_string($amount) && preg_match('/^-?\d+$/', trim($amount)) === 1) $amount = (int) trim($amount);
        if (!is_int($amount) || $amount <= 0) throw new \InvalidArgumentException('amount_minor must be a positive integer');

        $currency = strtoupper(trim(Arr::str($data, 'currency')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) throw new \InvalidArgumentException('currency must be a 3-letter ISO code');

        $interval = strtolower(trim(Arr::str($data, 'interval')));
        if (!in_array($interval, ['weekly', 'monthly', 'yearly'], true)) throw new \InvalidArgumentException('interval must be weekly, monthly, or yearly');

        $intervalCount = $data['interval_count'] ?? 1;
        if (is_string($intervalCount) && preg_match('/^\d+$/', trim($intervalCount)) === 1) $intervalCount = (int) trim($intervalCount);
        if (!is_int($intervalCount) || $intervalCount < 1 || $intervalCount > 12) throw new \InvalidArgumentException('interval_count must be between 1 and 12');

        $trialPeriod = strtolower(trim(Arr::str($data, 'trial_period')));
        if ($trialPeriod !== '' && !in_array($trialPeriod, ['weekly', 'monthly', 'yearly'], true)) throw new \InvalidArgumentException('trial_period must be weekly, monthly, yearly, or null');
        $trialCount = $data['trial_period_count'] ?? 0;
        if ($trialCount === '') $trialCount = 0;
        if (is_string($trialCount) && preg_match('/^\d+$/', trim($trialCount)) === 1) $trialCount = (int) trim($trialCount);
        if (!is_int($trialCount) || $trialCount < 0 || $trialCount > 90) throw new \InvalidArgumentException('trial_period_count must be between 0 and 90');
        if ($trialCount > 0 && $trialPeriod === '') throw new \InvalidArgumentException('trial_period is required when trial_period_count is set');

        $state = strtolower(trim(Arr::str($data, 'state', 'active')));
        if (!in_array($state, ['active', 'disabled'], true)) throw new \InvalidArgumentException('state must be active or disabled');

        $provider = trim(Arr::str($data, 'provider'));
        if ($provider !== '' && mb_strlen($provider) > 64) throw new \InvalidArgumentException('provider must be at most 64 characters');

        return [
            'name' => $name,
            'amount_minor' => $amount,
            'currency' => $currency,
            'interval' => $interval,
            'interval_count' => $intervalCount,
            'trial_period' => $trialPeriod === '' ? null : $trialPeriod,
            'trial_period_count' => $trialCount,
            'state' => $state,
            'provider' => $provider === '' ? null : $provider,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        return [
            'id' => Arr::str($row, 'id'),
            'name' => Arr::str($row, 'name'),
            'amount_minor' => Arr::int($row, 'amount_minor'),
            'currency' => Arr::str($row, 'currency'),
            'interval' => Arr::str($row, 'interval'),
            'interval_count' => Arr::int($row, 'interval_count', 1),
            'trial_period' => Arr::nullableStr($row, 'trial_period'),
            'trial_period_count' => Arr::int($row, 'trial_period_count'),
            'state' => Arr::str($row, 'state', 'active'),
            'provider' => Arr::nullableStr($row, 'provider'),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
