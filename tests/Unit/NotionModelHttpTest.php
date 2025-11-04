<?php

namespace Tests\Unit;

use App\Models\NotionModel;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ReflectionProperty;
use Tests\TestCase;

class NotionModelHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.notion_data_source_id' => 'test-data-source-id',
            'app.timezone' => 'UTC',
        ]);
    }

    public function test_it_posts_expected_payloads_for_collections_and_upcoming_events(): void
    {
        $history = [];
        $model = $this->createModelWithMockHandler([
            new Response(200, [], json_encode(['results' => [['id' => 'collection-1']]])),
            new Response(200, [], json_encode(['results' => [['id' => 'event-1']]])),
        ], $history);

        $collections = $model->getCollectionsFromNotion('primary-calendar');
        $events = $model->getUpcomingNotionEvents('2024-01-01', '2024-01-31', ['Private', 'Skip']);

        $this->assertCount(1, $collections);
        $this->assertCount(1, $events);
        $this->assertCount(2, $history);

        $firstRequest = $history[0]['request'];
        $this->assertSame('POST', $firstRequest->getMethod());
        $this->assertSame('/data_sources/test-data-source-id/query', $firstRequest->getUri()->getPath());

        $firstBody = json_decode((string) $firstRequest->getBody(), true);
        $this->assertSame([
            'filter' => [
                'property' => 'googleCalendarId',
                'rich_text' => [
                    'equals' => 'primary-calendar',
                ],
            ],
        ], $firstBody);

        $secondRequest = $history[1]['request'];
        $this->assertSame('POST', $secondRequest->getMethod());
        $this->assertSame('/data_sources/test-data-source-id/query', $secondRequest->getUri()->getPath());

        $secondBody = json_decode((string) $secondRequest->getBody(), true);
        $this->assertArrayHasKey('filter', $secondBody);
        $this->assertArrayHasKey('and', $secondBody['filter']);

        $expectedFilters = [
            [
                'property' => 'Date',
                'date' => [
                    'on_or_after' => '2024-01-01',
                ],
            ],
            [
                'property' => 'Date',
                'date' => [
                    'on_or_before' => '2024-01-31',
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
                    'does_not_contain' => 'Private',
                ],
            ],
            [
                'property' => 'ジャンル',
                'multi_select' => [
                    'does_not_contain' => 'Skip',
                ],
            ],
        ];

        $this->assertSame($expectedFilters, $secondBody['filter']['and']);
    }

    public function test_regist_notion_event_posts_page_payload_and_returns_true_on_success(): void
    {
        $history = [];
        $model = $this->createModelWithMockHandler([
            new Response(200, [], json_encode(['id' => 'page-id'])),
        ], $history);

        $event = $this->createCalendarEvent();

        $result = $model->registNotionEvent($event, 'Work');

        $this->assertTrue($result);
        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/pages', $request->getUri()->getPath());

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('data_source_id', $body['parent']['type']);
        $this->assertSame('test-data-source-id', $body['parent']['data_source_id']);
        $this->assertArrayHasKey('properties', $body);
        $this->assertArrayHasKey('Name', $body['properties']);
        $this->assertArrayHasKey('Date', $body['properties']);
        $this->assertSame('Work', $body['properties']['ジャンル']['multi_select'][0]['name']);
    }

    public function test_regist_notion_event_returns_false_when_not_successful(): void
    {
        $history = [];
        $model = $this->createModelWithMockHandler([
            new Response(500, [], json_encode(['error' => 'Server error'])),
        ], $history);

        $event = $this->createCalendarEvent();

        $result = $model->registNotionEvent($event, 'Work');

        $this->assertFalse($result);
    }

    public function test_delete_notion_event_sends_delete_request_and_returns_true_on_success(): void
    {
        $history = [];
        $model = $this->createModelWithMockHandler([
            new Response(200, [], json_encode(['deleted' => true])),
        ], $history);

        $result = $model->deleteNotionEvent('notion-event-id');

        $this->assertTrue($result);
        $this->assertCount(1, $history);

        $request = $history[0]['request'];
        $this->assertSame('DELETE', $request->getMethod());
        $this->assertSame('/blocks/notion-event-id', $request->getUri()->getPath());
    }

    private function createModelWithMockHandler(array $responses, array &$history): NotionModel
    {
        $history = [];
        $mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($history));

        $client = new Client([
            'handler' => $handlerStack,
            'base_uri' => 'https://api.notion.com/v1/',
        ]);

        $model = new NotionModel();

        $clientProperty = new ReflectionProperty(NotionModel::class, 'client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($model, $client);

        $cacheProperty = new ReflectionProperty(NotionModel::class, 'dataSourceIdCache');
        $cacheProperty->setAccessible(true);
        $cacheProperty->setValue([]);

        return $model;
    }

    private function createCalendarEvent(): Event
    {
        $event = new Event();
        $event->setId('google-event-id');
        $event->setSummary('Sample Event');
        $event->setDescription('Event description');
        $event->setLocation('Event location');

        $start = new EventDateTime();
        $start->setDateTime('2024-01-01T09:00:00+00:00');
        $event->setStart($start);

        $end = new EventDateTime();
        $end->setDateTime('2024-01-01T10:00:00+00:00');
        $event->setEnd($end);

        return $event;
    }
}
