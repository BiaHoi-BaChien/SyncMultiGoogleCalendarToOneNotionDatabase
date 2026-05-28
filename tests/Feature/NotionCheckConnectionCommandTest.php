<?php

namespace Tests\Feature;

use App\Models\NotionModel;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use ReflectionProperty;
use Tests\TestCase;

class NotionCheckConnectionCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.notion_api_token', 'test-token');
        config()->set('app.notion_version', '2026-03-11');
        config()->set('app.notion_database_id_of_calendar', 'test-database');
        config()->set('app.notion_data_source_id', null);

        $this->clearDataSourceCache();
    }

    protected function tearDown(): void
    {
        $this->clearDataSourceCache();

        parent::tearDown();
    }

    public function test_command_passes_when_version_dataSourceAndRequiredPropertiesAreValid(): void
    {
        $this->instance(NotionModel::class, $this->createModelWithResponses([
            new Response(200, [], json_encode([
                'data_sources' => [
                    ['id' => 'resolved-data-source'],
                ],
            ])),
            new Response(200, [], json_encode([
                'properties' => $this->validProperties(),
            ])),
        ]));

        $this->artisan('notion:check-connection')
            ->expectsOutput('NOTION_VERSION is valid: 2026-03-11')
            ->expectsOutput('Notion data source resolved: resolved-data-source')
            ->expectsOutput('Notion connection check passed.')
            ->assertExitCode(0);
    }

    public function test_command_fails_whenNotionVersionDoesNotSupportDataSources(): void
    {
        config()->set('app.notion_version', '2023-05-23');
        $this->instance(NotionModel::class, $this->createModelWithResponses([]));

        $this->artisan('notion:check-connection')
            ->expectsOutput("NOTION_VERSION must be 2025-09-03 or later for Notion data source API. Current: '2023-05-23'.")
            ->assertExitCode(1);
    }

    public function test_command_fails_whenDataSourceCannotBeResolved(): void
    {
        $this->instance(NotionModel::class, $this->createModelWithResponses([
            new Response(200, [], json_encode([
                'data_sources' => [],
            ])),
        ]));

        $this->artisan('notion:check-connection')
            ->expectsOutput('NOTION_VERSION is valid: 2026-03-11')
            ->expectsOutput('Notion connection check failed: Unable to resolve Notion data source id for database test-database.')
            ->assertExitCode(1);
    }

    public function test_command_fails_whenRequiredPropertyIsMissing(): void
    {
        $properties = $this->validProperties();
        unset($properties['ジャンル']);

        config()->set('app.notion_data_source_id', 'configured-data-source');
        $this->instance(NotionModel::class, $this->createModelWithResponses([
            new Response(200, [], json_encode([
                'properties' => $properties,
            ])),
        ]));

        $this->artisan('notion:check-connection')
            ->expectsOutput('Notion data source resolved: configured-data-source')
            ->expectsOutput("Notion schema validation failed: required property 'ジャンル' is missing.")
            ->assertExitCode(1);
    }

    public function test_command_fails_whenRequiredPropertyTypeIsWrong(): void
    {
        $properties = $this->validProperties();
        $properties['googleCalendarId']['type'] = 'title';

        config()->set('app.notion_data_source_id', 'configured-data-source');
        $this->instance(NotionModel::class, $this->createModelWithResponses([
            new Response(200, [], json_encode([
                'properties' => $properties,
            ])),
        ]));

        $this->artisan('notion:check-connection')
            ->expectsOutput("Notion schema validation failed: property 'googleCalendarId' must be 'rich_text', actual 'title'.")
            ->assertExitCode(1);
    }

    private function createModelWithResponses(array $responses): NotionModel
    {
        $mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mockHandler);

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

    private function validProperties(): array
    {
        return [
            'Name' => ['type' => 'title', 'title' => []],
            'Date' => ['type' => 'date', 'date' => []],
            'ジャンル' => ['type' => 'multi_select', 'multi_select' => []],
            'googleCalendarId' => ['type' => 'rich_text', 'rich_text' => []],
        ];
    }

    private function clearDataSourceCache(): void
    {
        $property = new ReflectionProperty(NotionModel::class, 'dataSourceIdCache');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }
}
