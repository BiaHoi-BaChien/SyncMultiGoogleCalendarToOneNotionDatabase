<?php

namespace Tests\Unit;

use App\Services\SlackNotifier;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SlackNotifierTest extends TestCase
{
    private const WEBHOOK_URL = 'https://hooks.slack.com/services/T00000000/B00000000/testsecret';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_send_posts_json_to_configured_webhook(): void
    {
        config()->set('app.slack_bot_enabled', 'true');
        config()->set('app.slack_webhook_url', ' ' . self::WEBHOOK_URL . ' ');
        Http::fake(function ($request, $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(10, $options['timeout']);

            return Http::response("ok\n");
        });

        (new SlackNotifier())->send('同期レポート');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === self::WEBHOOK_URL
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/json')
            && !$request->hasHeader('Authorization')
            && $request->data() === ['text' => "[Notion連携Googleカレンダー]\n同期レポート"]);
    }

    #[DataProvider('disabledSettings')]
    public function test_disabled_or_unconfigured_notifier_sends_nothing(bool $enabled, string $url): void
    {
        Http::fake();
        $notifier = new SlackNotifier($enabled, $url);

        $this->assertFalse($notifier->isEnabled());
        $notifier->send('同期レポート');
        $notifier->notifyError(new RuntimeException('Mail delivery failed.'));

        Http::assertNothingSent();
    }

    public static function disabledSettings(): array
    {
        return [[false, self::WEBHOOK_URL], [true, ''], [true, '   ']];
    }

    #[DataProvider('invalidWebhookUrls')]
    public function test_invalid_webhook_is_rejected_without_request_or_url_disclosure(string $url): void
    {
        Http::fake();
        try {
            (new SlackNotifier(true, $url))->send('同期レポート');
            $this->fail('Invalid webhook must fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('Slack Webhook URL is invalid.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }

        Http::assertNothingSent();
    }

    public static function invalidWebhookUrls(): array
    {
        return [
            ['https://estoreteamkg.slack.com/marketplace/A0C5636496E-notifyfromhostinger'],
            ['http://hooks.slack.com/services/T00000000/B00000000/testsecret'],
            ['https://hooks.slack.com.example.com/services/T00000000/B00000000/testsecret'],
            ['https://hooks.slack.com@localhost/services/T00000000/B00000000/testsecret'],
            ['https://hooks.slack.com/services/incomplete'],
            [self::WEBHOOK_URL . '?extra=secret'],
        ];
    }

    #[DataProvider('failedResponses')]
    public function test_failed_response_is_reported_without_exposing_response_body(int $status, string $body, string $message): void
    {
        Http::fake([self::WEBHOOK_URL => Http::response($body, $status)]);
        try {
            (new SlackNotifier(true, self::WEBHOOK_URL))->send('同期レポート');
            $this->fail('Failed webhook response must fail.');
        } catch (RuntimeException $error) {
            $this->assertSame($message, $error->getMessage());
            $this->assertNull($error->getPrevious());
        }

        Http::assertSentCount(1);
    }

    public static function failedResponses(): array
    {
        return [
            [500, self::WEBHOOK_URL, 'Slack Webhook request failed: HTTP 500'],
            [302, 'ok', 'Slack Webhook request failed: HTTP 302'],
            [200, self::WEBHOOK_URL, 'Slack Webhook returned an unexpected response.'],
            [200, '{"ok":true}', 'Slack Webhook returned an unexpected response.'],
        ];
    }

    #[DataProvider('transportErrors')]
    public function test_transport_error_does_not_expose_webhook_or_previous_exception(string $errorClass): void
    {
        Http::fake(function () use ($errorClass) {
            throw new $errorClass('Request failed for ' . self::WEBHOOK_URL);
        });

        try {
            (new SlackNotifier(true, self::WEBHOOK_URL))->send('同期レポート');
            $this->fail('Transport failure must fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('Slack Webhook request failed.', $error->getMessage());
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('testsecret', (string) $error);
        }
    }

    public static function transportErrors(): array
    {
        return [[ConnectionException::class], [RuntimeException::class]];
    }

    public function test_notify_error_posts_separate_message(): void
    {
        Http::fake([self::WEBHOOK_URL => Http::response('ok')]);

        $notifier = new SlackNotifier(true, self::WEBHOOK_URL);

        $notifier->notifyError(new RuntimeException('Mail delivery failed.'));

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === self::WEBHOOK_URL
            && $request->data() === ['text' => "[Notion連携Googleカレンダー]\nエラー: [RuntimeException] Mail delivery failed."]);
    }

    public function test_notify_error_reports_sanitized_failure_without_throwing(): void
    {
        Http::fake(fn () => throw new ConnectionException(self::WEBHOOK_URL));
        $this->mock(ExceptionHandler::class, function ($mock) {
            $mock->shouldReceive('report')->once()->withArgs(fn ($error) =>
                $error instanceof RuntimeException
                && $error->getMessage() === 'Slack Webhook request failed.'
                && $error->getPrevious() === null);
        });

        (new SlackNotifier(true, self::WEBHOOK_URL))->notifyError(new RuntimeException('Mail delivery failed.'));
    }
}
