<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Modules\Consent\Application\ConsentService;
use Siro\Core\Controller;
use Siro\Core\Request;
use Siro\Core\Response;

final class ConsentController extends Controller
{
    public function __construct(private readonly ConsentService $consent)
    {
    }

    public function policy(Request $request): Response
    {
        return $this->success($this->consent->policy(), 'Consent policy')
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function store(Request $request): Response
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'necessary' => 'required|boolean',
            'analytics' => 'required|boolean',
            'marketing' => 'required|boolean',
            'policy_version' => 'required|string|max:32',
            'session_id' => 'nullable|string|max:128',
        ]);

        $necessary = $data['necessary'] ?? null;
        $analytics = $data['analytics'] ?? null;
        $marketing = $data['marketing'] ?? null;
        $policyVersion = $data['policy_version'] ?? null;
        if (!is_bool($necessary) || !is_bool($analytics) || !is_bool($marketing)) {
            return $this->error('Consent choices must be booleans', 422, [], 'INVALID_CONSENT');
        }
        if (!is_string($policyVersion) || trim($policyVersion) === '') {
            return $this->error('policy_version is required', 422, [], 'INVALID_CONSENT');
        }

        $headerSession = $request->header('X-Session-Id');
        $bodySession = $data['session_id'] ?? null;
        $sessionId = is_string($headerSession) && trim($headerSession) !== ''
            ? $headerSession
            : (is_string($bodySession) ? $bodySession : '');

        try {
            $result = $this->consent->record(
                ['necessary' => $necessary, 'analytics' => $analytics, 'marketing' => $marketing],
                $policyVersion,
                $sessionId,
                $request->ip(),
                (string) ($request->header('User-Agent', '') ?? ''),
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422, [], 'INVALID_CONSENT');
        }

        return $this->success($result, 'Consent recorded');
    }

    public function summary(Request $request): Response
    {
        return $this->success($this->consent->summary(), 'Consent summary')
            ->header('Cache-Control', 'private, no-store');
    }
}
