<?php

namespace App\Models;

use DateInterval;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use GuzzleHttp\Client;
use Google\Service\Calendar\Event;
use DateTime;
use DateTimeZone;

class NotionModel extends Model
{
    use HasFactory;
    private $client;
    private $databaseId;
    private $token;

    /**
     * NotionModel constructor.
     */
    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://api.notion.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . config('app.notion_api_token'),
                'Notion-Version' => '2022-06-28',
                'Content-Type' => 'application/json',
            ],
        ]);
        $this->databaseId = config('app.notion_database_id_of_calendar');
        $this->token = config('app.notion_api_token');
    }

    /**
     * 指定したGoogleカレンダーIDに対応するNotionのコレクションを取得する
     *
     * @param string $googleCalendarId
     * @return \Illuminate\Support\Collection
     */
    public function getCollectionsFromNotion(string $googleCalendarId)
    {
        $response = $this->client->post('databases/' . $this->databaseId . '/query', [
            'json' => [
                'filter' => [
                    'property' => 'googleCalendarId',
                    'rich_text' => [
                        'equals' => $googleCalendarId,
                    ],
                ],
            ],
        ]);

        $data = json_decode($response->getBody(), true);
        return collect($data['results']);
    }

    /**
     * 今日以降、指定した日付までのNotionイベントを取得する
     *
     * @param string $start_date
     * @param string $end_date
     * @return \Illuminate\Support\Collection
     */
    public function getUpcomingNotionEvents($start_date, $end_date)
    {
        $response = $this->client->post('databases/' . $this->databaseId . '/query', [
            'json' => [
                'filter' => [
                    'and' => [
                        [
                            'property' => 'Date',
                            'date' => [
                                'on_or_after' => $start_date
                            ],
                        ],
                        [
                            'property' => 'Date',
                            'date' => [
                                'on_or_before' => $end_date
                            ],
                        ],
                        [
                            'property' => 'googleCalendarId',
                            'rich_text' => [
                                'is_not_empty' => true
                            ],
                        ],
                    ],
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

        $response = $this->client->post('pages', [
            'json' => [
                'parent' => ['database_id' => $this->databaseId],
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
                    ['text' => ['content' => $event->summary]]
                ]
            ];
        }
        if (!is_null($event->start->dateTime)) {
            $start_date = new DateTime($event->start->dateTime, new DateTimeZone(config('app.timezone')));
            $end_date = new DateTime($event->end->dateTime, new DateTimeZone(config('app.timezone')));
            $properties['Date'] = ['date' => ['start' => $start_date->format(DateTime::ATOM), 'end' => $end_date->format(DateTime::ATOM)]];
        } else {
            $start_date = new DateTime($event->start->date, new DateTimeZone(config('app.timezone')));
            $end_date = new DateTime($event->end->date, new DateTimeZone(config('app.timezone')));
            // 終日イベントの場合、終了日を1日減らし、時間を設定しない
            $end_date->sub(new DateInterval('P1D'));
            $properties['Date'] = ['date' => ['start' => $start_date->format('Y-m-d'), 'end' => $end_date->format('Y-m-d')]];
        }

        if (!is_null($notion_label)) {
            $properties['ジャンル'] = ['multi_select' => [['name' => $notion_label]]];
        }

        $properties['googleCalendarId'] = ['rich_text' => [['text' => ['content' => $event->id]]]];

        if (!is_null($event->description)) {
            $properties['メモ'] = ['rich_text' => [['text' => ['content' => $event->description]]]];
        }

        if (!is_null($event->location)) {
            $properties['Location'] = ['rich_text' => [['text' => ['content' => $event->location]]]];
        }

        return $properties;
    }

}
