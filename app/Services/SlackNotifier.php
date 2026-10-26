<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SlackNotifier
{
    private bool $enabled;

    private string $webhookUrl;

    public function __construct(?bool $enabled = null, ?string $webhookUrl = null)
    {
        $this->enabled = $this->normalizeEnabled($enabled ?? config('app.slack_bot_enabled'));
        $this->webhookUrl = trim($webhookUrl ?? config('app.slack_webhook_url') ?? '');
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->webhookUrl !== '';
    }

    public function send(string $text): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if (preg_match('~\Ahttps://hooks\.slack\.com/services/[A-Za-z0-9]+/[A-Za-z0-9]+/[A-Za-z0-9]+\z~', $this->webhookUrl) !== 1) {
            throw new RuntimeException('Slack Webhook URL is invalid.');
        }

        // 外部由来の本文をSlackのメンションやリンク構文として解釈させない。
        $text = strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);

        try {
            $response = Http::connectTimeout(5)->timeout(10)->withoutRedirecting()
                ->post($this->webhookUrl, ['text' => "[Notion連携Googleカレンダー]\n" . $text]);
        } catch (Throwable) {
            // Webhook URLには秘密情報が含まれるため、元の例外をログへ渡さない。
            throw new RuntimeException('Slack Webhook request failed.');
        }

        if (!$response->successful()) {
            throw new RuntimeException('Slack Webhook request failed: HTTP ' . $response->status());
        }

        if (trim($response->body()) !== 'ok') {
            throw new RuntimeException('Slack Webhook returned an unexpected response.');
        }
    }

    public function notifyError(Throwable $error): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $summary = trim((string) $error->getMessage());
        if ($summary === '') {
            $summary = class_basename($error);
        }
        $summary = mb_substr($summary, 0, 200);
        $text = sprintf('エラー: [%s] %s', class_basename($error), $summary);

        try {
            $this->send($text);
        } catch (Throwable $notificationError) {
            report($notificationError);
        }
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
