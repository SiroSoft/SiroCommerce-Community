<?php

declare(strict_types=1);

namespace App\Modules\Platform\Infrastructure;

use App\Models\OutboxMessage;
use App\Support\Arr;
use Siro\Core\Database;
use Siro\Core\Event;

/** Leases outbox rows so multiple operational workers cannot publish the same row. */
final class OutboxDispatcher
{
    public function __construct(private readonly int $maxAttempts = 10)
    {
    }

    /** @return array{count:int,published:int,failed:int,dead:int,messages:array<int,array<string,mixed>>} */
    public function dispatch(int $limit, bool $publish): array
    {
        if ($limit < 1) throw new \InvalidArgumentException('Outbox limit must be positive');
        $messages = [];
        $published = 0;
        $failed = 0;
        $dead = 0;
        $worker = bin2hex(random_bytes(8));

        for ($i = 0; $i < $limit; $i++) {
            $row = $this->claim($worker);
            if ($row === null) break;
            $messages[] = $row;
            $id = Arr::str($row, 'id');
            if (!$publish) {
                $this->release($id);
                continue;
            }

            $payload = json_decode(Arr::str($row, 'payload_json', '{}'), true);
            $error = null;
            try {
                $ok = Event::emit(Arr::str($row, 'topic'), is_array($payload) ? $payload : []);
            } catch (\Throwable $e) {
                $ok = false;
                $error = $e->getMessage();
            }
            if ($ok) {
                $this->update($id, ['state' => 'published', 'published_at' => date('Y-m-d H:i:s'), 'attempts' => Arr::int($row, 'attempts') + 1, 'locked_at' => null, 'last_error' => null]);
                $published++;
                continue;
            }

            $attempts = Arr::int($row, 'attempts') + 1;
            $state = $attempts >= $this->maxAttempts ? 'dead' : 'pending';
            $this->update($id, [
                'state' => $state,
                'attempts' => $attempts,
                'available_at' => date('Y-m-d H:i:s', time() + min(3600, 2 ** min($attempts, 10))),
                'locked_at' => null,
                'last_error' => $error ?? 'Siro Event listener rejected event',
            ]);
            $state === 'dead' ? $dead++ : $failed++;
        }

        return ['count' => count($messages), 'published' => $published, 'failed' => $failed, 'dead' => $dead, 'messages' => $messages];
    }

    /** @return array<string,mixed>|null */
    private function claim(string $worker): ?array
    {
        $claimed = Database::transaction(function () use ($worker): ?array {
            $now = date('Y-m-d H:i:s');
            foreach (OutboxMessage::query()->where('state', 'dispatching')->whereRaw('locked_at IS NOT NULL AND locked_at <= :lease_now', ['lease_now' => $now])->get() as $staleItem) {
                $this->recoverLease($staleItem);
            }
            /** @var \Siro\Core\Model|null $row */
            $row = OutboxMessage::query()
                ->where('state', 'pending')
                ->whereRaw('(available_at IS NULL OR available_at <= :available_now)', ['available_now' => $now])
                ->orderBy('available_at')
                ->lockForUpdate()
                ->first();
            if ($row === null) return null;
            $row->setAttribute('state', 'dispatching');
            $row->setAttribute('locked_at', $now);
            $row->save();
            /** @var array<string, mixed> $data */
            $data = Arr::row($row);
            $data['worker_id'] = $worker;
            return $data;
        });
        /** @var array<string, mixed>|null $claimed */
        return is_array($claimed) ? $claimed : null;
    }

    private function release(string $id): void
    {
        $this->update($id, ['state' => 'pending', 'locked_at' => null]);
    }

    /** @param array<string,mixed> $changes */
    private function update(string $id, array $changes): void
    {
        $row = OutboxMessage::find($id);
        if ($row === null) return;
        foreach ($changes as $key => $value) $row->setAttribute($key, $value);
        $row->save();
    }

    private function recoverLease(mixed $item): void
    {
        $row = is_array($item) ? OutboxMessage::find($this->idOf($item)) : ($item instanceof \Siro\Core\Model ? $item : null);
        if ($row !== null) { $row->setAttribute('state', 'pending'); $row->setAttribute('locked_at', null); $row->save(); }
    }

    /** @param array<mixed, mixed> $item */
    private function idOf(array $item): string
    {
        $map = [];
        foreach ($item as $key => $value) if (is_string($key)) $map[$key] = $value;
        return Arr::str($map, 'id');
    }
}
