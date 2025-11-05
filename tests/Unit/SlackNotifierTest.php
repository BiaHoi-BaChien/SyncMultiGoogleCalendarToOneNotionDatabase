<?php

namespace Tests\Unit;

use App\Services\SlackNotifier;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SlackNotifierTest extends TestCase
{
    public function test_notify_error_posts_thread_message(): void
    {
        Http::fake();

        $notifier = new SlackNotifier(true, 'xoxb-test', 'U99999999');

        $notifier->notifyError(['U99999999' => '1714976400.000100'], new RuntimeException('Mail delivery failed.'));

        Http::assertSent(function ($request) {
            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && $request['channel'] === 'U99999999'
                && $request['thread_ts'] === '1714976400.000100'
                && $request['text'] === 'エラー: [RuntimeException] Mail delivery failed.';
        });
    }
}
