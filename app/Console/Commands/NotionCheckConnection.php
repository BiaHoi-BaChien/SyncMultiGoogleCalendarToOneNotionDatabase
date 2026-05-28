<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Throwable;

class NotionCheckConnection extends Command
{
    private const MIN_NOTION_VERSION = '2025-09-03';

    private const REQUIRED_PROPERTIES = [
        'Name' => 'title',
        'Date' => 'date',
        'ジャンル' => 'multi_select',
        'googleCalendarId' => 'rich_text',
    ];

    protected $signature = 'notion:check-connection';

    protected $description = 'Validate Notion API version, data source resolution, and required calendar properties.';

    public function handle(): int
    {
        try {
            if (!$this->validateNotionVersion()) {
                return self::FAILURE;
            }

            $notion = app('App\\Models\\NotionModel');

            $dataSourceId = $notion->resolveCalendarDataSourceId();
            if ($dataSourceId === '') {
                $this->error('Notion data source resolution failed: resolved data source id is empty.');
                return self::FAILURE;
            }

            $this->info('Notion data source resolved: ' . $dataSourceId);

            $schema = $notion->getCalendarDataSourceSchema();
            $properties = $schema['properties'] ?? null;
            if (!is_array($properties)) {
                $this->error('Notion schema validation failed: data source response does not include properties.');
                return self::FAILURE;
            }

            foreach (self::REQUIRED_PROPERTIES as $name => $expectedType) {
                if (!array_key_exists($name, $properties)) {
                    $this->error("Notion schema validation failed: required property '{$name}' is missing.");
                    return self::FAILURE;
                }

                $actualType = $properties[$name]['type'] ?? null;
                if ($actualType !== $expectedType) {
                    $actual = is_string($actualType) && $actualType !== '' ? $actualType : 'unknown';
                    $this->error("Notion schema validation failed: property '{$name}' must be '{$expectedType}', actual '{$actual}'.");
                    return self::FAILURE;
                }
            }

            $this->info('Notion connection check passed.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Notion connection check failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function validateNotionVersion(): bool
    {
        $version = (string) config('app.notion_version');

        if ($version === '') {
            $this->error('NOTION_VERSION is not configured. Set NOTION_VERSION=' . self::MIN_NOTION_VERSION . ' or later.');
            return false;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $version)) {
            $this->error("NOTION_VERSION is invalid: '{$version}'. Expected YYYY-MM-DD.");
            return false;
        }

        if (strcmp($version, self::MIN_NOTION_VERSION) < 0) {
            $this->error("NOTION_VERSION must be " . self::MIN_NOTION_VERSION . " or later for Notion data source API. Current: '{$version}'.");
            return false;
        }

        $this->info('NOTION_VERSION is valid: ' . $version);
        return true;
    }
}
