<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SlackNotifier
{
    private bool $enabled;

    private ?string $token;

    /**
     * @var array<int, string>
     */
    private array $userIds;

    public function __construct(?bool $enabled = null, ?string $token = null, ?string $userIds = null)
    {
        $this->enabled = $this->normalizeEnabled($enabled ?? config('app.slack_bot_enabled'));
        $this->token = $token ?? config('app.slack_bot_token');
        $this->userIds = $this->parseUserIds($userIds ?? config('app.slack_dm_user_ids'));
    }

    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->token) && !empty($this->userIds);
    }

    /**
     * @return array<string, string>
     */
    public function send(string $text): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];
        foreach ($this->userIds as $userId) {
            $response = Http::withToken($this->token)->post('https://slack.com/api/chat.postMessage', [
                'channel' => $userId,
                'text' => $text,
            ]);

            if (!$response->successful()) {
                throw new RuntimeException('Slack API request failed: HTTP ' . $response->status());
            }

            $data = $response->json();
            if (!is_array($data) || ($data['ok'] ?? false) !== true || !isset($data['ts'])) {
                $errorCode = is_array($data) ? ($data['error'] ?? 'unknown_error') : 'invalid_response';
                throw new RuntimeException('Slack API error: ' . $errorCode);
            }

            $results[$userId] = (string) $data['ts'];
        }

        return $results;
    }

    /**
     * @param array<string, string> $messages
     */
    public function notifyError(array $messages, Throwable $error): void
    {
        if (!$this->isEnabled() || empty($messages)) {
            return;
        }

        $summary = trim((string) $error->getMessage());
        if ($summary === '') {
            $summary = class_basename($error);
        }
        $summary = mb_substr($summary, 0, 200);
        $text = sprintf('エラー: [%s] %s', class_basename($error), $summary);

        foreach ($messages as $userId => $threadTs) {
            try {
                Http::withToken($this->token)->post('https://slack.com/api/chat.postMessage', [
                    'channel' => $userId,
                    'text' => $text,
                    'thread_ts' => $threadTs,
                ]);
            } catch (Throwable $threadError) {
                report($threadError);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function parseUserIds(?string $userIds): array
    {
        if ($userIds === null) {
            return [];
        }

        $ids = array_filter(array_map('trim', explode(',', $userIds)));

        return array_values($ids);
    }

    private function normalizeEnabled($enabled): bool
    {
        if (is_bool($enabled)) {
            return $enabled;
        }

        if ($enabled === null) {
            return false;
        }

        return filter_var($enabled, FILTER_VALIDATE_BOOL) ?? false;
    }
}
