<?php

namespace Tests\Support\Fakes;

class GoogleCalendarModelFake
{
    public static array $eventLists = [];
    public static array $getListCalls = [];
    public static array $isUserParticipatingResults = [];
    public static array $isUserParticipatingCalls = [];

    public static function reset(): void
    {
        self::$eventLists = [];
        self::$getListCalls = [];
        self::$isUserParticipatingResults = [];
        self::$isUserParticipatingCalls = [];
    }

    public function getGoogleCalendarEventList($start, $end, $calendarId)
    {
        self::$getListCalls[] = [$start, $end, $calendarId];

        return self::$eventLists[$calendarId] ?? [];
    }

    public function isUserParticipating(object $event): bool
    {
        self::$isUserParticipatingCalls[] = $event->id;

        if (array_key_exists($event->id, self::$isUserParticipatingResults)) {
            $result = self::$isUserParticipatingResults[$event->id];
            return is_callable($result) ? (bool) $result($event) : (bool) $result;
        }

        return true;
    }
}
