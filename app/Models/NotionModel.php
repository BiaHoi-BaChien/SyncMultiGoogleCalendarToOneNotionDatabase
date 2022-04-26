<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use FiveamCode\LaravelNotionApi\Notion;
use FiveamCode\LaravelNotionApi\Query\Filters\Filter;
use FiveamCode\LaravelNotionApi\Query\Filters\Operators;
use FiveamCode\LaravelNotionApi\Query\Sorting;
use FiveamCode\LaravelNotionApi\Entities\Page;
use Google\Service\Calendar\Event;
use DateTime;
use DateTimeZone;

class NotionModel extends Model
{
    use HasFactory;

    public function getCollectionsFromNotion(string $googleCalendarId)
    {
        $notion = new Notion((string)config('app.notion_api_token'));

        // 現在Notionに登録されているデータを取得
        $sortings = new Collection();
        $filters = new Collection();
        $filters->add(
            Filter::textFilter("googleCalendarId", Operators::EQUALS, $googleCalendarId), 
        );
        $collections = $notion->database(config('app.notion_database_id_of_calendar'))
            ->filterBy($filters) // filters are optional
            ->query()
            ->asCollection();

        return $collections;
    }

    /**
     * regist page to Notion
     *
     * @param Google\Service\Calendar\Event $event
     * @param String $notion_label
     * @return boolean
     */
    public function registNotion(Event $event, String $notion_label)
    {
        $page = new Page();

        $page = $this->setPropaties($page, $event, $notion_label);

        \Notion::pages()->createInDatabase(config('app.notion_database_id_of_calendar'), $page);

        return true;
    }

    /**
     * update page to Notion
     *
     * @param Google\Service\Calendar\Event $event
     * @param String $notion_label
     * @param FiveamCode\LaravelNotionApi\Entities\Page $collenction
     * 
     * @return boolean
     */
    public function updateNotion(Event $event, String $notion_label, Page $collection)
    {
        $page_id = $this->getPageId($collection->getUrl());

        $page = new Page();
        $page->setId($page_id);
        $page = $this->setPropaties($page, $event, $notion_label);

        \Notion::pages()->update($page);

        return true;
    }

    /**
     * @param FiveamCode\LaravelNotionApi\Entities\Page $page
     * @param Google\Service\Calendar\Event $event
     * @param String $notion_label
     * 
     * @return FiveamCode\LaravelNotionApi\Entities\Page $page
     */
    private function setPropaties(Page $page, Event $event, String $notion_label)
    {
        $page->setTitle("Name", $event->summary);

        if (is_null($event->start->date)) {
            $start_date = new DateTime($event->start->dateTime, new DateTimeZone(config('app.timezone')));
            $end_date = new DateTime($event->end->dateTime, new DateTimeZone(config('app.timezone')));
        } else {
            $start_date = new DateTime($event->start->date, new DateTimeZone(config('app.timezone')));
            $end_date = new DateTime($event->end->date, new DateTimeZone(config('app.timezone')));
        }

        $diff_date_time = $start_date->diff($end_date);
        if ($diff_date_time->format("%Y%M%D%H%I%S") === "000001000000") {
            $page->setDate("Date", $start_date);
        } else {
            $page->setDate("Date", $start_date, $end_date);
        }

        if (!is_null($notion_label)) {
            $page->setMultiSelect("ジャンル", [$notion_label]);
        }

        $page->setText("googleCalendarId", $event->id);

        if (!is_null($event->description)) {
            $description = "\"". $event->description ."\"";
            $page->setText("メモ", $description);
        }

        if (!is_null($event->location)) {
            $location = "\"". $event->location ."\"";
            $page->setText("Location", $location);
        }
 
        return $page;
    }

    /**
     * get pageId from object Page
     * 
     * @param String $url
     * @return String $page_id
     */
    private function getPageId(String $url)
    {
        $notion_host = config('app.notion_host');
        if (!preg_match("/^$notion_host/", $url)) {
            return null;
        }
        $page_id = preg_replace("/$notion_host/", '', $url);

        if (preg_match('/-/', $page_id)) {
            $result = explode("-", $page_id);
            $page_id = end($result);
        }

        return $page_id;
    }
}
