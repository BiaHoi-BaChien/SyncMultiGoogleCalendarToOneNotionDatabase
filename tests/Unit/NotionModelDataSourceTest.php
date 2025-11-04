<?php

namespace Tests\Unit;

use App\Models\NotionModel;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

class NotionModelDataSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.notion_api_token', 'test-token');
        config()->set('app.notion_version', '2023-05-23');
        config()->set('app.notion_database_id_of_calendar', 'test-database');
        config()->set('app.notion_data_source_id', null);

        $this->clearDataSourceCache();
    }

    protected function tearDown(): void
    {
        $this->clearDataSourceCache();

        parent::tearDown();
    }

    public function test_getDataSourceId_usesConfiguredValueWithoutHttpRequests(): void
    {
        config()->set('app.notion_data_source_id', 'configured-id');

        $history = [];
        $notionModel = $this->createModelWithMockClient([], $history);

        $result = $this->invokeGetDataSourceId($notionModel);
        $this->assertSame('configured-id', $result);
        $this->assertCount(0, $history);

        config()->set('app.notion_data_source_id', null);
        $second = $this->invokeGetDataSourceId($notionModel);
        $this->assertSame('configured-id', $second);
        $this->assertCount(0, $history);
    }

    public function test_getDataSourceId_usesStaticCacheWhenAvailable(): void
    {
        $this->setDataSourceCache(['test-database' => 'cached-id']);

        $history = [];
        $notionModel = $this->createModelWithMockClient([], $history);

        $result = $this->invokeGetDataSourceId($notionModel);
        $this->assertSame('cached-id', $result);
        $this->assertCount(0, $history);
    }

    public function test_getDataSourceId_fetchesFromApiWhenNotCached(): void
    {
        $responses = [
            new Response(200, [], json_encode([
                'data_sources' => [
                    ['id' => 'mocked-id'],
                ],
            ])),
        ];

        $history = [];
        $notionModel = $this->createModelWithMockClient($responses, $history);

        $result = $this->invokeGetDataSourceId($notionModel);

        $this->assertSame('mocked-id', $result);
        $this->assertCount(1, $history);
        $this->assertSame('GET', $history[0]['request']->getMethod());
        $this->assertStringEndsWith('/databases/test-database', (string) $history[0]['request']->getUri());

        $cache = $this->getDataSourceCache();
        $this->assertSame('mocked-id', $cache['test-database']);
    }

    public function test_getDataSourceId_throwsWhenApiResponseMissingId(): void
    {
        $responses = [
            new Response(200, [], json_encode([
                'data_sources' => [
                    ['name' => 'missing-id'],
                ],
            ])),
        ];

        $history = [];
        $notionModel = $this->createModelWithMockClient($responses, $history);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve Notion data source id for database test-database.');

        $this->invokeGetDataSourceId($notionModel);
    }

    private function createModelWithMockClient(array $responses, array &$history): NotionModel
    {
        $history = [];

        $mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mockHandler);
        $handlerStack->push(Middleware::history($history));

        $client = new Client([
            'handler' => $handlerStack,
            'base_uri' => 'https://api.notion.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . config('app.notion_api_token'),
                'Notion-Version' => config('app.notion_version'),
                'Content-Type' => 'application/json',
            ],
        ]);

        $model = new NotionModel();

        $clientProperty = new ReflectionProperty(NotionModel::class, 'client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($model, $client);

        return $model;
    }

    private function invokeGetDataSourceId(NotionModel $model): string
    {
        $method = new ReflectionMethod(NotionModel::class, 'getDataSourceId');
        $method->setAccessible(true);

        return $method->invoke($model);
    }

    private function clearDataSourceCache(): void
    {
        $property = new ReflectionProperty(NotionModel::class, 'dataSourceIdCache');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }

    private function setDataSourceCache(array $cache): void
    {
        $property = new ReflectionProperty(NotionModel::class, 'dataSourceIdCache');
        $property->setAccessible(true);
        $property->setValue(null, $cache);
    }

    private function getDataSourceCache(): array
    {
        $property = new ReflectionProperty(NotionModel::class, 'dataSourceIdCache');
        $property->setAccessible(true);

        return $property->getValue();
    }
}
