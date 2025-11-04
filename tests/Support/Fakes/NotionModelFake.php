<?php

namespace Tests\Support\Fakes;

use Illuminate\Support\Collection;

class NotionModelFake
{
    public static Collection $upcomingEventsReturn;
    public static array $collectionsReturn = [];
    public static array $registResults = [];
    public static array $deleteCalls = [];
    public static int $getUpcomingCalls = 0;
    public static array $getUpcomingArgs = [];
    public static array $getCollectionsCalls = [];
    public static array $registCalls = [];

    public static function reset(): void
    {
        self::$upcomingEventsReturn = collect();
        self::$collectionsReturn = [];
        self::$registResults = [];
        self::$deleteCalls = [];
        self::$getUpcomingCalls = 0;
        self::$getUpcomingArgs = [];
        self::$getCollectionsCalls = [];
        self::$registCalls = [];
    }

    public function getUpcomingNotionEvents($start, $end, $excludeLabels)
    {
        self::$getUpcomingCalls++;
        self::$getUpcomingArgs[] = [$start, $end, $excludeLabels];

        return self::$upcomingEventsReturn;
    }

    public function getCollectionsFromNotion(string $eventId)
    {
        self::$getCollectionsCalls[] = $eventId;

        return self::$collectionsReturn[$eventId] ?? collect();
    }

    public function registNotionEvent(object $event, string $label)
    {
        self::$registCalls[] = [$event, $label];

        $result = self::$registResults[$event->id] ?? false;
        if (is_callable($result)) {
            return $result($event, $label);
        }

        return (bool) $result;
    }

    public function deleteNotionEvent(string $pageId): bool
    {
        self::$deleteCalls[] = $pageId;

        return true;
    }
}
