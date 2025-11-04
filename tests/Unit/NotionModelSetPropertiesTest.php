<?php

namespace Tests\Unit;

use App\Models\NotionModel;
use DateTime;
use DateTimeZone;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use ReflectionMethod;
use Tests\TestCase;

class NotionModelSetPropertiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Asia/Tokyo');
    }

    public function test_setPropaties_formatsIdenticalStartAndEndDateTime(): void
    {
        $event = $this->createEventWithDateTime(
            'event-1',
            'Sample Event',
            '2023-10-10T09:00:00+09:00',
            '2023-10-10T09:00:00+09:00'
        );
        $event->setDescription('Detailed description');
        $event->setLocation('Meeting Room');

        $properties = $this->invokeSetProperties($event, 'Work');

        $expectedStart = (new DateTime('2023-10-10T09:00:00+09:00', new DateTimeZone(config('app.timezone'))))
            ->format(DateTime::ATOM);

        $this->assertSame(
            ['date' => ['start' => $expectedStart]],
            $properties['Date']
        );

        $this->assertSame(
            ['multi_select' => [['name' => 'Work']]],
            $properties['ジャンル']
        );

        $this->assertSame('event-1', $properties['googleCalendarId']['rich_text'][0]['text']['content']);
        $this->assertSame('Detailed description', $properties['メモ']['rich_text'][0]['text']['content']);
        $this->assertSame('Meeting Room', $properties['Location']['rich_text'][0]['text']['content']);
    }

    public function test_setPropaties_formatsDistinctStartAndEndDateTime(): void
    {
        $event = $this->createEventWithDateTime(
            'event-2',
            'Another Event',
            '2023-10-10T09:00:00+09:00',
            '2023-10-11T10:30:00+09:00'
        );
        $event->setDescription(null);
        $event->setLocation(null);

        $properties = $this->invokeSetProperties($event, 'Personal');

        $expectedStart = (new DateTime('2023-10-10T09:00:00+09:00', new DateTimeZone(config('app.timezone'))))
            ->format(DateTime::ATOM);
        $expectedEnd = (new DateTime('2023-10-11T10:30:00+09:00', new DateTimeZone(config('app.timezone'))))
            ->format(DateTime::ATOM);

        $this->assertSame(
            ['date' => ['start' => $expectedStart, 'end' => $expectedEnd]],
            $properties['Date']
        );

        $this->assertSame('event-2', $properties['googleCalendarId']['rich_text'][0]['text']['content']);
        $this->assertArrayNotHasKey('メモ', $properties);
        $this->assertArrayNotHasKey('Location', $properties);
    }

    public function test_setPropaties_formatsAllDayEvent(): void
    {
        $event = $this->createAllDayEvent(
            'event-3',
            'All Day Event',
            '2023-10-10',
            '2023-10-13'
        );

        $properties = $this->invokeSetProperties($event, 'Holiday');

        $this->assertSame(
            ['date' => ['start' => '2023-10-10', 'end' => '2023-10-12']],
            $properties['Date']
        );

        $this->assertSame('event-3', $properties['googleCalendarId']['rich_text'][0]['text']['content']);
    }

    private function createEventWithDateTime(string $id, string $summary, string $start, string $end): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setSummary($summary);

        $startDateTime = new EventDateTime();
        $startDateTime->setDateTime($start);
        $event->setStart($startDateTime);

        $endDateTime = new EventDateTime();
        $endDateTime->setDateTime($end);
        $event->setEnd($endDateTime);

        return $event;
    }

    private function createAllDayEvent(string $id, string $summary, string $startDate, string $endDate): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setSummary($summary);

        $startDateTime = new EventDateTime();
        $startDateTime->setDate($startDate);
        $event->setStart($startDateTime);

        $endDateTime = new EventDateTime();
        $endDateTime->setDate($endDate);
        $event->setEnd($endDateTime);

        return $event;
    }

    private function invokeSetProperties(Event $event, string $label): array
    {
        $model = new NotionModel();

        $method = new ReflectionMethod(NotionModel::class, 'setPropaties');
        $method->setAccessible(true);

        return $method->invoke($model, $event, $label);
    }
}
