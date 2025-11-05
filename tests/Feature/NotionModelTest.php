<?php

namespace Tests\Feature;

use App\Models\NotionModel;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use ReflectionProperty;
use Tests\TestCase;

class NotionModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.notion_api_token', 'test-token');
        config()->set('app.notion_version', '2023-05-23');
        config()->set('app.notion_database_id_of_calendar', 'test-database');
        config()->set('app.notion_data_source_id', 'test-data-source-id');
        config()->set('app.timezone', 'Asia/Tokyo');

        $this->clearDataSourceCache();
    }

    protected function tearDown(): void
    {
        $this->clearDataSourceCache();

        parent::tearDown();
    }

    public function test_get_collections_from_notion_returns_collection_of_results(): void
    {
        $history = [];
        $model = $this->createModelWithResponses([
            new Response(200, [], json_encode([
                'results' => [
                    ['id' => 'notion-page-1'],
                    ['id' => 'notion-page-2'],
                ],
            ])),
        ], $history);

        $collections = $model->getCollectionsFromNotion('primary-calendar');

        $this->assertInstanceOf(Collection::class, $collections);
        $this->assertSame(['notion-page-1', 'notion-page-2'], $collections->pluck('id')->all());
        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/data_sources/test-data-source-id/query', $request->getUri()->getPath());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame([
            'filter' => [
                'property' => 'googleCalendarId',
                'rich_text' => [
                    'equals' => 'primary-calendar',
                ],
            ],
        ], $body);
    }

    public function test_get_upcoming_notion_events_applies_date_and_label_filters(): void
    {
        $history = [];
        $model = $this->createModelWithResponses([
            new Response(200, [], json_encode([
                'results' => [
                    ['id' => 'upcoming-event-1'],
                ],
            ])),
        ], $history);

        $start = '2024-05-01';
        $end = '2024-05-07';

        $events = $model->getUpcomingNotionEvents($start, $end, ['Holiday', 'Private']);

        $this->assertInstanceOf(Collection::class, $events);
        $this->assertSame(['upcoming-event-1'], $events->pluck('id')->all());

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/data_sources/test-data-source-id/query', $request->getUri()->getPath());

        $body = json_decode((string) $request->getBody(), true);

        $this->assertSame([
            'filter' => [
                'and' => [
                    [
                        'property' => 'Date',
                        'date' => [
                            'on_or_after' => $start,
                        ],
                    ],
                    [
                        'property' => 'Date',
                        'date' => [
                            'on_or_before' => $end,
                        ],
                    ],
                    [
                        'property' => 'googleCalendarId',
                        'rich_text' => [
                            'is_not_empty' => true,
                        ],
                    ],
                    [
                        'property' => 'ジャンル',
                        'multi_select' => [
                            'does_not_contain' => 'Holiday',
                        ],
                    ],
                    [
                        'property' => 'ジャンル',
                        'multi_select' => [
                            'does_not_contain' => 'Private',
                        ],
                    ],
                ],
            ],
        ], $body);
    }

    public function test_regist_notion_event_posts_all_day_event_payload(): void
    {
        $history = [];
        $model = $this->createModelWithResponses([
            new Response(200, [], json_encode(['id' => 'page-id'])),
        ], $history);

        $event = new Event();
        $event->setId('google-event-id');
        $event->setSummary('Company Retreat');

        $start = new EventDateTime();
        $start->setDate('2024-02-10');
        $event->setStart($start);

        $end = new EventDateTime();
        $end->setDate('2024-02-12');
        $event->setEnd($end);

        $result = $model->registNotionEvent($event, 'Holiday');

        $this->assertTrue($result);
        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/v1/pages', $request->getUri()->getPath());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('data_source_id', $body['parent']['type']);
        $this->assertSame('test-data-source-id', $body['parent']['data_source_id']);

        $this->assertSame('Company Retreat', $body['properties']['Name']['title'][0]['text']['content']);
        $this->assertSame([
            'date' => [
                'start' => '2024-02-10',
                'end' => '2024-02-11',
            ],
        ], $body['properties']['Date']);
        $this->assertSame('Holiday', $body['properties']['ジャンル']['multi_select'][0]['name']);
        $this->assertSame('google-event-id', $body['properties']['googleCalendarId']['rich_text'][0]['text']['content']);
    }

    private function createModelWithResponses(array $responses, array &$history): NotionModel
    {
        $history = [];
        $mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($history));

        $client = new Client([
            'handler' => $handlerStack,
            'base_uri' => 'https://api.notion.com/v1/',
            'http_errors' => false,
        ]);

        $model = new NotionModel();

        $clientProperty = new ReflectionProperty(NotionModel::class, 'client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($model, $client);

        return $model;
    }

    private function clearDataSourceCache(): void
    {
        $property = new ReflectionProperty(NotionModel::class, 'dataSourceIdCache');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }
}
