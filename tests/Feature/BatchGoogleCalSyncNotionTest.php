<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Command\Command;
use Tests\Support\Fakes\GoogleCalendarModelFake;
use Tests\Support\Fakes\NotionModelFake;
use Tests\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BatchGoogleCalSyncNotionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(\App\Models\NotionModel::class, false)) {
            class_alias(NotionModelFake::class, \App\Models\NotionModel::class);
        }

        if (!class_exists(\App\Models\GoogleCalendarModel::class, false)) {
            class_alias(GoogleCalendarModelFake::class, \App\Models\GoogleCalendarModel::class);
        }

        NotionModelFake::reset();
        GoogleCalendarModelFake::reset();
    }

    private function setDefaultCalendarConfig(): void
    {
        config()->set('app.google_calendar_id_personal', 'calendar-personal');
        config()->set('app.google_calendar_label_personal', 'Personal Label');

        config()->set('app.google_calendar_id_business', null);
        config()->set('app.google_calendar_label_business', 'Business Label');

        config()->set('app.google_calendar_id_school', null);
        config()->set('app.google_calendar_label_school', 'School Label');

        config()->set('app.google_calendar_id_holiday', 'calendar-holiday');
        config()->set('app.google_calendar_label_holiday', 'Holiday Label');

        config()->set('app.sync_max_days', 0);
    }

    public function test_command_sends_mail_when_new_events_synced(): void
    {
        $this->setDefaultCalendarConfig();
        config()->set('app.sync_report_mail_to', 'notify@example.com');

        $event = (object) ['id' => 'event-1'];

        NotionModelFake::$upcomingEventsReturn = collect();
        NotionModelFake::$collectionsReturn = [
            'event-1' => collect(),
        ];
        NotionModelFake::$registResults = [
            'event-1' => true,
        ];

        GoogleCalendarModelFake::$eventLists = [
            'calendar-personal' => [$event],
        ];

        Mail::fake();

        $this->artisan('command:gcal-sync-notion')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, NotionModelFake::$getUpcomingCalls);
        $this->assertSame(['event-1'], NotionModelFake::$getCollectionsCalls);
        $this->assertCount(1, NotionModelFake::$registCalls);
        $this->assertSame([], NotionModelFake::$deleteCalls);

        Mail::assertSent(function ($message) {
            $original = method_exists($message, 'getOriginalMessage')
                ? $message->getOriginalMessage()
                : $message->getSymfonyMessage();

            $subject = method_exists($original, 'getSubject') ? $original->getSubject() : null;
            $body = null;
            if (method_exists($original, 'getTextBody')) {
                $body = $original->getTextBody();
            } elseif (method_exists($original, 'getBody')) {
                $bodyObject = $original->getBody();
                if (method_exists($bodyObject, 'bodyToString')) {
                    $body = $bodyObject->bodyToString();
                } elseif (method_exists($bodyObject, '__toString')) {
                    $body = (string) $bodyObject;
                }
            }

            return $subject === 'Notion同期レポート'
                && is_string($body)
                && str_contains($body, 'Personal Label: 1件')
                && str_contains($body, '以上の予定をNotionデータベースに同期しました。');
        });
    }

    public function test_command_skips_mail_when_only_existing_events(): void
    {
        $this->setDefaultCalendarConfig();
        config()->set('app.sync_report_mail_to', 'notify@example.com');

        $event = (object) ['id' => 'event-1'];

        NotionModelFake::$upcomingEventsReturn = collect();
        NotionModelFake::$collectionsReturn = [
            'event-1' => collect(['existing']),
        ];

        GoogleCalendarModelFake::$eventLists = [
            'calendar-personal' => [$event],
        ];

        Mail::fake();

        $this->artisan('command:gcal-sync-notion')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, NotionModelFake::$getUpcomingCalls);
        $this->assertSame(['event-1'], NotionModelFake::$getCollectionsCalls);
        $this->assertSame([], NotionModelFake::$registCalls);
        $this->assertSame([], NotionModelFake::$deleteCalls);

        Mail::assertNothingSent();
    }

    public function test_command_does_not_delete_events_in_holiday_mode(): void
    {
        $this->setDefaultCalendarConfig();
        config()->set('app.sync_report_mail_to', 'notify@example.com');

        $event = (object) ['id' => 'holiday-event'];

        NotionModelFake::$collectionsReturn = [
            'holiday-event' => collect(),
        ];
        NotionModelFake::$registResults = [
            'holiday-event' => true,
        ];

        GoogleCalendarModelFake::$eventLists = [
            'calendar-holiday' => [$event],
        ];

        Mail::fake();

        $this->artisan('command:gcal-sync-notion', ['mode' => 'holiday'])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, NotionModelFake::$getUpcomingCalls);
        $this->assertSame(['holiday-event'], NotionModelFake::$getCollectionsCalls);
        $this->assertCount(1, NotionModelFake::$registCalls);
        $this->assertSame([], NotionModelFake::$deleteCalls);

        Mail::assertSent(function ($message) {
            $original = method_exists($message, 'getOriginalMessage')
                ? $message->getOriginalMessage()
                : $message->getSymfonyMessage();

            $subject = method_exists($original, 'getSubject') ? $original->getSubject() : null;
            $body = null;
            if (method_exists($original, 'getTextBody')) {
                $body = $original->getTextBody();
            } elseif (method_exists($original, 'getBody')) {
                $bodyObject = $original->getBody();
                if (method_exists($bodyObject, 'bodyToString')) {
                    $body = $bodyObject->bodyToString();
                } elseif (method_exists($bodyObject, '__toString')) {
                    $body = (string) $bodyObject;
                }
            }

            return $subject === 'Notion同期レポート'
                && is_string($body)
                && str_contains($body, 'Holiday Label: 1件');
        });
    }

    public function test_command_deletes_orphaned_notion_events(): void
    {
        $this->setDefaultCalendarConfig();
        config()->set('app.sync_report_mail_to', 'notify@example.com');

        $notionEvents = collect([
            [
                'id' => 'notion-event-1',
                'properties' => [
                    'googleCalendarId' => [
                        'rich_text' => [
                            [
                                'text' => [
                                    'content' => 'missing-google-event',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        NotionModelFake::$upcomingEventsReturn = $notionEvents;
        NotionModelFake::$collectionsReturn = [
            'event-existing' => collect(['existing']),
        ];

        GoogleCalendarModelFake::$eventLists = [
            'calendar-personal' => [(object) ['id' => 'event-existing']],
        ];

        Mail::fake();

        $this->artisan('command:gcal-sync-notion')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, NotionModelFake::$getUpcomingCalls);
        $this->assertSame(['event-existing'], NotionModelFake::$getCollectionsCalls);
        $this->assertSame([], NotionModelFake::$registCalls);
        $this->assertSame(['notion-event-1'], NotionModelFake::$deleteCalls);

        Mail::assertNothingSent();
    }
}
