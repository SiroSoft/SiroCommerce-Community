<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ConsentRecord;
use App\Support\Arr;
use App\Support\Ids;

/**
 * Consent records are keyed by (session_hash, policy_version). Reprocessing the
 * same session/version updates the existing row instead of appending a new one.
 */
final class ConsentRecordRepository
{
    /**
     * @param array{necessary: true, analytics: bool, marketing: bool} $choices
     * @return array{record: array<string, mixed>, created: bool}
     */
    public function store(string $sessionHash, string $policyVersion, array $choices, ?string $ipHash, ?string $userAgent): array
    {
        $now = date('Y-m-d H:i:s');
        $encoded = json_encode($choices, JSON_THROW_ON_ERROR);

        $existing = ConsentRecord::query()
            ->where('session_hash', $sessionHash)
            ->where('policy_version', $policyVersion)
            ->first();

        if ($existing instanceof ConsentRecord) {
            $existing->fill([
                'choices_json' => $encoded,
                'ip_hash' => $ipHash,
                'user_agent' => $userAgent,
                'updated_at' => $now,
            ]);
            $existing->save();

            return ['record' => Arr::row($existing), 'created' => false];
        }

        $record = new ConsentRecord();
        $record->fill([
            'id' => Ids::new(),
            'session_hash' => $sessionHash,
            'choices_json' => $encoded,
            'policy_version' => $policyVersion,
            'ip_hash' => $ipHash,
            'user_agent' => $userAgent,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $record->save();
            return ['record' => Arr::row($record), 'created' => true];
        } catch (\PDOException $exception) {
            // A concurrent first write can win the unique key race; update it.
            if (!in_array((string) $exception->getCode(), ['19', '23000'], true)) {
                throw $exception;
            }
            $winner = ConsentRecord::query()
                ->where('session_hash', $sessionHash)
                ->where('policy_version', $policyVersion)
                ->first();
            if (!$winner instanceof ConsentRecord) {
                throw $exception;
            }
            $winner->fill([
                'choices_json' => $encoded,
                'ip_hash' => $ipHash,
                'user_agent' => $userAgent,
                'updated_at' => $now,
            ]);
            $winner->save();

            return ['record' => Arr::row($winner), 'created' => false];
        }
    }

    /** @return array<string, mixed>|null Latest record for a session hash, if any. */
    public function findBySessionHash(string $sessionHash): ?array
    {
        $row = ConsentRecord::query()->where('session_hash', $sessionHash)->orderBy('updated_at', 'desc')->first();
        return $row === null ? null : Arr::row($row);
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $rows = [];
        foreach (ConsentRecord::query()->orderBy('created_at', 'asc')->get() as $record) {
            $rows[] = Arr::row($record);
        }
        return $rows;
    }
}
