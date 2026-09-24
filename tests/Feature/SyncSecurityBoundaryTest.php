<?php

namespace Tests\Feature;

use App\Mail\SyncReportMail;
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
            $this->schemaResponse(),
            new Response(200, [], json_encode(['results' => $existing])),
            new Response(200, [], '{}'),
        ], $history);

        $this->artisan('command:gcal-sync-notion')->assertExitCode(0);

        $this->assertCount(3, $history);
        $this->assertSame('PATCH', $history[2]['request']->getMethod());
        $this->assertSame('/v1/pages/notion-removed', $history[2]['request']->getUri()->getPath());
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
        $this->notion([$this->schemaResponse(), new Response(200, [], '{"results":[]}'), $acceptBoundedPage, $acceptBoundedPage], $history);

        $this->artisan('command:gcal-sync-notion')->assertExitCode(0);

        $this->assertCount(4, $history);
        $first = json_decode((string) $history[2]['request']->getBody(), true)['properties'];
        $second = json_decode((string) $history[3]['request']->getBody(), true)['properties'];
        $this->assertStringContainsString('上限文字数', $first['Name']['title'][0]['text']['content']);
        $this->assertStringContainsString('上限文字数', $first['Location']['rich_text'][0]['text']['content']);
        $this->assertSame('normal', $second['googleCalendarId']['rich_text'][0]['text']['content']);
        $this->assertSame('normal title', $second['Name']['title'][0]['text']['content']);
    }

    public function test_selected_fields_preserve_participation_event_details_and_deletion_reports(): void
    {
        config(['app.sync_report_mail_to' => 'sync@example.test']);
        $today = date('Y-m-d');
        $invitation = [
            'id' => 'invitation', 'summary' => 'Meeting', 'description' => 'Agenda', 'location' => 'Room A',
            'start' => ['dateTime' => $today.'T09:00:00+00:00'],
            'end' => ['dateTime' => $today.'T10:00:00+00:00'],
            'attendees' => [
                ['responseStatus' => 'declined'],
                ['self' => true, 'responseStatus' => 'needsAction'],
            ],
        ];
        $declined = $this->event('declined');
        $declined['attendees'] = [['self' => true, 'responseStatus' => 'declined']];
        $this->google([
            $this->page([$invitation, $declined], 'next'),
            $this->page([$this->event('existing'), $this->event('all-day')]),
        ]);
        $removed = $this->notionPage('removed');
        $removed['properties']['Name'] = ['title' => [['plain_text' => 'Removed meeting']]];
        $history = [];
        $this->notion([
            $this->schemaResponse(),
            new Response(200, [], json_encode([
                'results' => [$this->notionPage('existing')], 'has_more' => true, 'next_cursor' => 'next',
            ])),
            new Response(200, [], json_encode(['results' => [$removed], 'has_more' => false])),
            new Response(200, [], '{}'),
            new Response(200, [], '{}'),
            new Response(200, [], '{}'),
        ], $history);

        $this->artisan('command:gcal-sync-notion')->assertExitCode(0);

        $this->assertCount(6, $history);
        $meeting = json_decode((string) $history[3]['request']->getBody(), true)['properties'];
        $allDay = json_decode((string) $history[4]['request']->getBody(), true)['properties'];
        $this->assertSame('Meeting', $meeting['Name']['title'][0]['text']['content']);
        $this->assertSame('Agenda', $meeting['メモ']['rich_text'][0]['text']['content']);
        $this->assertSame('Room A', $meeting['Location']['rich_text'][0]['text']['content']);
        $this->assertSame($invitation['start']['dateTime'], $meeting['Date']['date']['start']);
        $this->assertSame($invitation['end']['dateTime'], $meeting['Date']['date']['end']);
        $this->assertSame('all-day', $allDay['googleCalendarId']['rich_text'][0]['text']['content']);
        $this->assertSame(['start' => $today], $allDay['Date']['date']);
        $this->assertSame('PATCH', $history[5]['request']->getMethod());
        $this->assertSame('/v1/pages/notion-removed', $history[5]['request']->getUri()->getPath());
        Mail::assertSent(SyncReportMail::class, function (SyncReportMail $mail) use ($today) {
            return $mail->totals === ['Personal' => 3]
                && $mail->details['Personal'] === [
                    ['action' => '追加', 'start' => $today.' 09:00', 'summary' => 'Meeting'],
                    ['action' => '追加', 'start' => $today, 'summary' => 'all-day title'],
                    ['action' => '削除', 'start' => $today, 'summary' => 'Removed meeting'],
                ];
        });
    }

    public function test_missing_required_notion_property_stops_before_any_write(): void
    {
        $this->google([$this->page([$this->event('new')])]);
        $history = [];
        $this->notion([new Response(200, [], '{"properties":{}}')], $history);

        $this->artisan('command:gcal-sync-notion')->assertExitCode(1);

        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        Mail::assertNothingSent();
    }

    private function schemaResponse(): Response
    {
        return new Response(200, [], json_encode(['properties' => [
            'Name' => ['id' => 'title'],
            'Date' => ['id' => 'date-id'],
            'ジャンル' => ['id' => 'genre-id'],
            'googleCalendarId' => ['id' => 'google-id'],
        ]]));
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
