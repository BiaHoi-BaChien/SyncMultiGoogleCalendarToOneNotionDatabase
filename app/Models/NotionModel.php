<?php

namespace App\Models;

use DateInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use GuzzleHttp\Client;
use Google\Service\Calendar\Event;
use DateTime;
use DateTimeZone;
use RuntimeException;

class NotionModel extends Model
{
    use HasFactory;
    private $client;
    private $databaseId;
    private $token;
    private $dataSourceId;

    private static $dataSourceIdCache = [];

    /**
     * NotionModel constructor.
     */
    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.notion.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . config('app.notion_api_token'),
                'Notion-Version' => config('app.notion_version'),
                'Content-Type' => 'application/json',
            ],
        ]);
        $this->databaseId = config('app.notion_database_id_of_calendar');
        $this->token = config('app.notion_api_token');
        $this->dataSourceId = null;
    }

    /**
     * Resolve the data source identifier, preferring configuration but falling back to discovery.
     *
     * @return string
     */
    private function getDataSourceId()
    {
        if (!empty($this->dataSourceId)) {
            return $this->dataSourceId;
        }

        $configuredId = config('app.notion_data_source_id');
        if (!empty($configuredId)) {
            $this->dataSourceId = $configuredId;
            return $this->dataSourceId;
        }

        if (isset(self::$dataSourceIdCache[$this->databaseId])) {
            $this->dataSourceId = self::$dataSourceIdCache[$this->databaseId];
            return $this->dataSourceId;
        }

        $response = $this->client->get('databases/' . $this->databaseId);
        $data = json_decode($response->getBody(), true);

        if (!empty($data['data_sources'][0]['id'])) {
            $this->dataSourceId = $data['data_sources'][0]['id'];
            self::$dataSourceIdCache[$this->databaseId] = $this->dataSourceId;
            return $this->dataSourceId;
        }

        throw new RuntimeException('Unable to resolve Notion data source id for database ' . $this->databaseId . '.');
    }

    /**
     * 指定したGoogleカレンダーIDに対応するNotionのコレクションを取得する
     *
     * @param string $googleCalendarId
     * @param string $notionLabel
     * @return \Illuminate\Support\Collection
     */
    public function getCollectionsFromNotion(string $googleCalendarId, string $notionLabel)
    {
        $dataSourceId = $this->getDataSourceId();

        $filters = [
            [
                'property' => 'googleCalendarId',
                'rich_text' => [
                    'equals' => $googleCalendarId,
                ],
            ],
        ];

        if ($notionLabel !== '') {
            $filters[] = [
                'property' => 'ジャンル',
                'multi_select' => [
                    'contains' => $notionLabel,
                ],
            ];
        }

        $response = $this->client->post('data_sources/' . $dataSourceId . '/query', [
            'json' => [
                'filter' => [
                    'and' => $filters,
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        return collect($data['results']);
    }

    /**
     * 指定した開始日から終了日までの範囲に含まれる Notion イベントを取得する。
     *
     * @param string $start_date   取得する期間の開始日（ISO 8601 形式の日付文字列）。
     * @param string $end_date     取得する期間の終了日（ISO 8601 形式の日付文字列）。
     * @param array  $excludeLabels 取得対象から除外するジャンル名の配列。
     * @return \Illuminate\Support\Collection 取得したイベントのコレクション。
     */
    public function getUpcomingNotionEvents($start_date, $end_date, $excludeLabels = [])
    {
        $filters = [
            [
                'property' => 'Date',
                'date' => [
                    'on_or_after' => $start_date,
                ],
            ],
            [
                'property' => 'Date',
                'date' => [
                    'on_or_before' => $end_date,
                ],
            ],
            [
                'property' => 'googleCalendarId',
                'rich_text' => [
                    'is_not_empty' => true,
                ],
            ],
        ];

        foreach ($excludeLabels as $label) {
            $filters[] = [
                'property' => 'ジャンル',
                'multi_select' => [
                    'does_not_contain' => $label,
                ],
            ];
        }

        $dataSourceId = $this->getDataSourceId();

        $response = $this->client->post('data_sources/' . $dataSourceId . '/query', [
            'json' => [
                'filter' => [
                    'and' => $filters,
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        return collect($data['results']);
    }

    /**
     * Notionにイベントを登録する
     *
     * @param Event $event
     * @param string $notion_label
     * @return bool
     */
    public function registNotionEvent(Event $event, String $notion_label)
    {
        $page = $this->setPropaties($event, $notion_label);

        $dataSourceId = $this->getDataSourceId();

        $response = $this->client->post('pages', [
            'json' => [
                'parent' => [
                    'type' => 'data_source_id',
                    'data_source_id' => $dataSourceId,
                ],
                'properties' => $page,
            ],
        ]);

        return $response->getStatusCode() === 200;
    }

    /**
     * Notionからイベントを削除する
     *
     * @param string $eventId
     * @return bool
     */
    public function deleteNotionEvent(string $eventId)
    {
        $response = $this->client->delete('blocks/' . $eventId);

        return $response->getStatusCode() === 200;
    }

    /**
     * イベントのプロパティを設定する
     *
     * @param Event $event
     * @param string $notion_label
     * @return array
     */
    private function setPropaties(Event $event, String $notion_label)
    {
        $properties = [];

        if (!is_null($event->summary)){
            $properties['Name'] = [
                'title' => [
                    [
                        'type' => 'text',
                        'text' => ['content' => $event->summary],
                    ],
                ],
            ];
        }
        if (!is_null($event->start->dateTime)) {
            $start_date = new DateTime($event->start->dateTime, new DateTimeZone(config('app.timezone')));
            $end_date = new DateTime($event->end->dateTime, new DateTimeZone(config('app.timezone')));
            if ($start_date == $end_date) {
                $properties['Date'] = ['date' => ['start' => $start_date->format(DateTime::ATOM)]];
            } else {
                $properties['Date'] = ['date' => ['start' => $start_date->format(DateTime::ATOM), 'end' => $end_date->format(DateTime::ATOM)]];
            }
        } else {
            $start_date = new DateTime($event->start->date, new DateTimeZone(config('app.timezone')));
            $end_date = new DateTime($event->end->date, new DateTimeZone(config('app.timezone')));
            // 終日イベントの場合、終了日を1日減らし、時間を設定しない
            $end_date->sub(new DateInterval('P1D'));
            if ($start_date == $end_date) {
                $properties['Date'] = ['date' => ['start' => $start_date->format('Y-m-d')]];
            } else {
                $properties['Date'] = ['date' => ['start' => $start_date->format('Y-m-d'), 'end' => $end_date->format('Y-m-d')]];
            }
        }

        if (!is_null($notion_label)) {
            $properties['ジャンル'] = ['multi_select' => [['name' => $notion_label]]];
        }

        $properties['googleCalendarId'] = [
            'rich_text' => [
                [
                    'type' => 'text',
                    'text' => ['content' => $event->id],
                ],
            ],
        ];

        if (!is_null($event->description)) {
            $properties['メモ'] = [
                'rich_text' => [
                    [
                        'type' => 'text',
                        'text' => ['content' => $event->description],
                    ],
                ],
            ];
        }

        if (!is_null($event->location)) {
            $properties['Location'] = [
                'rich_text' => [
                    [
                        'type' => 'text',
                        'text' => ['content' => $event->location],
                    ],
                ],
            ];
        }

        return $properties;
    }

    /**
     * Validate that a given value is a date string in YYYYMMDD format.
     *
     * @param mixed $targetDate
     * @return bool
     */
    private function validateTargetDate($targetDate)
    {
        if (is_null($targetDate)) {
            return false;
        }

        return preg_match('/^\d{8}$/', strval($targetDate)) === 1;
    }

    /**
     * Extract the page ID from a Notion page URL.
     *
     * @param string $pageUrl
     * @return string|null
     */
    private function getPageId($pageUrl)
    {
        if (!is_string($pageUrl)) {
            return null;
        }

        $parsed = parse_url($pageUrl);
        if ($parsed === false || !isset($parsed['host']) || !preg_match('/(?:^|\.)notion\.so$/', $parsed['host'])) {
            return null;
        }

        if (!isset($parsed['path'])) {
            return null;
        }

        $path = trim($parsed['path'], '/');
        if ($path === '') {
            return null;
        }

        $parts = explode('-', $path);
        return end($parts);
    }

}
