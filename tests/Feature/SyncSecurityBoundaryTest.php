<?php

namespace Tests\Feature;

use App\Models\GoogleCalendarModel;
use App\Models\NotionModel;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

class SyncSecurityBoundaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.timezone' => 'UTC', 'app.sync_max_days' => 1,
            'app.google_calendar_id_personal' => 'personal', 'app.google_calendar_label_personal' => 'Personal',
            'app.google_calendar_id_business' => null, 'app.google_calendar_label_business' => 'Work',
            'app.google_calendar_id_school' => null, 'app.google_calendar_id_holiday' => null,
            'app.google_calendar_label_holiday' => 'Holiday', 'app.notion_data_source_id' => 'offline-data-source',
            'app.slack_bot_enabled' => false, 'app.sync_report_mail_to' => null,
        ]);
        Mail::fake();
    }

    public function test_second_page_existing_event_is_not_trashed_and_real_removal_still_is(): void
    {
        $first = array_map(fn ($i) => $this->event('event-'.$i), range(1, 200));
        $last = $this->event('event-201');
        $this->google([$this->page($first, 'next'), $this->page([$last])]);
        $existing = array_map(fn ($event) => $this->notionPage($event['id']), [...$first, $last]);
        $existing[] = $this->notionPage('removed');
        $history = [];
        $this->notion([
            new Response(200, [], json_encode(['results' => $existing])),
            new Response(200, [], '{}'),
        ], $history);

        $this->artisan('command:gcal-sync-notion')->assertExitCode(0);

        $this->assertCount(2, $history);
        $this->assertSame('PATCH', $history[1]['request']->getMethod());
        $this->assertSame('/v1/pages/notion-removed', $history[1]['request']->getUri()->getPath());
    }

    #[DataProvider('unsafeSecondPages')]
    public function test_incomplete_google_snapshot_stops_before_any_notion_request(Response $second): void
    {
        $this->google([$this->page([$this->event('first')], 'next'), $second]);
        $history = [];
        $this->notion([], $history);

        $this->artisan('command:gcal-sync-notion')->assertExitCode(1);

        $this->assertSame([], $history);
    }

    public static function unsafeSecondPages(): array
    {
        return [
            'HTTP failure' => [new Response(403, [], '{"error":{"code":403,"message":"Denied"}}')],
            'repeated token' => [new Response(200, [], '{"kind":"calendar#events","nextPageToken":"next"}')],
            'invalid JSON' => [new Response(200, [], '{broken')],
        ];
    }

    public function test_long_invitation_does_not_block_the_following_calendar(): void
    {
        config(['app.google_calendar_id_business' => 'business']);
        $oversized = $this->event('oversized');
        $oversized['summary'] = str_repeat('あ', 2001);
        $oversized['location'] = str_repeat('📅', 1001);
        $oversized['description'] = str_repeat('a', 2001);
        $this->google([$this->page([$oversized]), $this->page([$this->event('normal')])]);
        $history = [];
        $acceptBoundedPage = static function ($request) {
            $properties = json_decode((string) $request->getBody(), true)['properties'];
            foreach (['Name' => 'title', 'Location' => 'rich_text', 'メモ' => 'rich_text'] as $name => $type) {
                foreach ($properties[$name][$type] ?? [] as $text) {
                    if (strlen(mb_convert_encoding($text['text']['content'], 'UTF-16LE', 'UTF-8')) > 4000) {
                        return new Response(400, [], '{"code":"validation_error"}');
                    }
                }
            }
            return new Response(200, [], '{"id":"created"}');
        };
        $this->notion([new Response(200, [], '{"results":[]}'), $acceptBoundedPage, $acceptBoundedPage], $history);

        $this->artisan('command:gcal-sync-notion')->assertExitCode(0);

        $this->assertCount(3, $history);
        $first = json_decode((string) $history[1]['request']->getBody(), true)['properties'];
        $second = json_decode((string) $history[2]['request']->getBody(), true)['properties'];
        $this->assertStringContainsString('上限文字数', $first['Name']['title'][0]['text']['content']);
        $this->assertStringContainsString('上限文字数', $first['Location']['rich_text'][0]['text']['content']);
        $this->assertSame('normal', $second['googleCalendarId']['rich_text'][0]['text']['content']);
        $this->assertSame('normal title', $second['Name']['title'][0]['text']['content']);
    }

    private function event(string $id): array
    {
        return ['id' => $id, 'summary' => $id.' title', 'start' => ['date' => date('Y-m-d')], 'end' => ['date' => date('Y-m-d', strtotime('+1 day'))]];
    }

    private function notionPage(string $id): array
    {
        return ['id' => 'notion-'.$id, 'properties' => [
            'googleCalendarId' => ['rich_text' => [['text' => ['content' => $id]]]],
            'ジャンル' => ['multi_select' => [['name' => 'Personal']]],
            'Date' => ['date' => ['start' => date('Y-m-d')]],
        ]];
    }

    private function page(array $items, ?string $token = null): Response
    {
        return new Response(200, [], json_encode(['kind' => 'calendar#events', 'items' => $items, 'nextPageToken' => $token]));
    }

    private function google(array $responses): void
    {
        $client = new GoogleClient();
        $client->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler($responses))]));
        $client->setAccessToken(['access_token' => 'offline-test', 'expires_in' => 3600, 'created' => time()]);
        $this->app->instance(GoogleCalendarModel::class, new class($client) extends GoogleCalendarModel {
            public function __construct(private GoogleClient $testClient) {}
            protected function getClient() { return $this->testClient; }
        });
    }

    private function notion(array $responses, array &$history): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $model = new NotionModel();
        (new ReflectionProperty(NotionModel::class, 'client'))->setValue($model, new Client(['base_uri' => 'https://api.notion.com/v1/', 'handler' => $stack]));
        $this->app->instance(NotionModel::class, $model);
    }
}
