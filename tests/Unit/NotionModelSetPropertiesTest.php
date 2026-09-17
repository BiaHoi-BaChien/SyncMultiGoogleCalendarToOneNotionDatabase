<?php

namespace Tests\Unit;

use App\Models\NotionModel;
use DateTime;
use DateTimeZone;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use ReflectionMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotionModelSetPropertiesTest extends TestCase
{
    #[DataProvider('textBoundaries')]
    public function test_title_and_location_respect_the_text_limit(string $character, int $length): void
    {
        $text = str_repeat($character, $length);
        $event = $this->createEventWithDateTime('bounded', $text, '2026-09-17T09:00:00Z', '2026-09-17T10:00:00Z');
        $event->setLocation($text);
        $properties = $this->invokeSetProperties($event, 'Work');
        $title = $properties['Name']['title'][0]['text']['content'];
        $location = $properties['Location']['rich_text'][0]['text']['content'];
        if (strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2 <= 2000) {
            $this->assertSame($text, $title);
            $this->assertSame($text, $location);
        } else {
            $this->assertSame('件名は上限文字数を超えているためGoogleカレンダーで確認してください', $title);
            $this->assertSame('場所は上限文字数を超えているためGoogleカレンダーで確認してください', $location);
        }
    }

    public static function textBoundaries(): array
    {
        return [
            'empty' => ['a', 0], 'ASCII limit' => ['a', 2000], 'ASCII oversized' => ['a', 2001],
            'Japanese limit' => ['あ', 2000], 'Japanese oversized' => ['あ', 2001],
            'emoji limit' => ['📅', 1000], 'emoji oversized' => ['📅', 1001],
        ];
    }

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

    public function test_setPropaties_keepsMemoAtNotionCharacterLimit(): void
    {
        $event = $this->createEventWithDateTime(
            'event-4',
            'Memo at character limit',
            '2023-10-10T09:00:00+09:00',
            '2023-10-10T10:00:00+09:00'
        );
        $description = str_repeat('あ', 2000);
        $event->setDescription($description);

        $properties = $this->invokeSetProperties($event, 'Work');

        $this->assertSame($description, $properties['メモ']['rich_text'][0]['text']['content']);
    }

    public function test_setPropaties_replacesMemoOverNotionCharacterLimit(): void
    {
        $event = $this->createEventWithDateTime(
            'event-5',
            'Memo over character limit',
            '2023-10-10T09:00:00+09:00',
            '2023-10-10T10:00:00+09:00'
        );
        $event->setDescription(str_repeat('あ', 2001));

        $properties = $this->invokeSetProperties($event, 'Work');

        $this->assertSame(
            'メモ本文は上限文字数を超えているためNotionからの同期はできませんでした',
            $properties['メモ']['rich_text'][0]['text']['content']
        );
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
