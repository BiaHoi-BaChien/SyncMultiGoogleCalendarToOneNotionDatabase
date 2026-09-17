<?php

namespace Tests\Unit;

use App\Models\GoogleCalendarModel;
use Google\Client as GoogleClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleCalendarPaginationTest extends TestCase
{
    public function test_it_collects_all_pages_including_an_empty_intermediate_page(): void
    {
        $history = [];
        $firstItems = array_map(fn ($i) => ['id' => 'event-'.$i], range(1, 200));
        $model = $this->model([
            $this->page($firstItems, 'page-2'),
            $this->page([], 'page-3'),
            $this->page([['id' => 'event-201']]),
        ], $history);

        $events = $model->getGoogleCalendarEventList('2026-09-01', '2026-09-30', 'test-calendar');

        $this->assertCount(201, $events);
        $this->assertSame('event-201', $events[200]->id);
        $this->assertCount(3, $history);
        parse_str($history[0]['request']->getUri()->getQuery(), $original);
        foreach ([1 => 'page-2', 2 => 'page-3'] as $index => $token) {
            parse_str($history[$index]['request']->getUri()->getQuery(), $query);
            $this->assertSame($token, $query['pageToken']);
            unset($query['pageToken']);
            $this->assertSame($original, $query);
        }
    }

    public function test_it_preserves_single_page_and_empty_calendar_results(): void
    {
        $history = [];
        $model = $this->model([$this->page([['id' => 'normal']]), $this->page([])], $history);
        $this->assertSame('normal', $model->getGoogleCalendarEventList('2026-09-01', '2026-09-30', 'test')[0]->id);
        $this->assertSame([], $model->getGoogleCalendarEventList('2026-09-01', '2026-09-30', 'test'));
    }

    #[DataProvider('incompletePages')]
    public function test_it_never_returns_a_partial_or_malformed_snapshot(array $responses): void
    {
        $history = [];
        $model = $this->model($responses, $history);
        $this->expectException(\Exception::class);
        $model->getGoogleCalendarEventList('2026-09-01', '2026-09-30', 'test');
    }

    public static function incompletePages(): array
    {
        $first = new Response(200, [], json_encode(['kind' => 'calendar#events', 'items' => [['id' => 'first']], 'nextPageToken' => 'again']));
        return [
            'later HTTP failure' => [[$first, new Response(403, [], '{"error":{"message":"Denied","code":403}}')]],
            'repeated token' => [[$first, $first]],
            'invalid JSON' => [[new Response(200, [], '{broken')]],
            'missing collection metadata' => [[new Response(200, [], '{}')]],
            'empty token' => [[new Response(200, [], '{"kind":"calendar#events","nextPageToken":""}')]],
            'non-string token' => [[new Response(200, [], '{"kind":"calendar#events","nextPageToken":42}')]],
        ];
    }

    private function page(array $items, ?string $token = null): Response
    {
        $body = ['kind' => 'calendar#events', 'items' => $items];
        if ($token !== null) {
            $body['nextPageToken'] = $token;
        }
        return new Response(200, [], json_encode($body));
    }

    private function model(array $responses, array &$history): GoogleCalendarModel
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $client = new GoogleClient();
        $client->setHttpClient(new Client(['handler' => $stack]));
        $client->setAccessToken(['access_token' => 'offline-test', 'expires_in' => 3600, 'created' => time()]);
        return new class($client) extends GoogleCalendarModel {
            public function __construct(private GoogleClient $testClient) {}
            protected function getClient() { return $this->testClient; }
        };
    }
}
