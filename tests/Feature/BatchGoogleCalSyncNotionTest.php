<?php

namespace Tests\Feature;

use App\Mail\SyncReportMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Tests\Support\Fakes\GoogleCalendarModelFake;
use Tests\Support\Fakes\NotionModelFake;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
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
        config()->set('app.sync_max_days', 3);

        $event = (object) [
            'id' => 'event-1',
            'summary' => 'Board Meeting',
            'start' => (object) ['dateTime' => '2024-05-10T09:00:00+09:00'],
        ];

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
        $expectedStart = date('Y-m-d');
        $expectedEnd = date('Y-m-d', strtotime('+3 day'));
        $this->assertSame([
            [$expectedStart, $expectedEnd, ['Holiday Label']],
        ], NotionModelFake::$getUpcomingArgs);
        $this->assertSame(['event-1'], NotionModelFake::$getCollectionsCalls);
        $this->assertCount(1, NotionModelFake::$registCalls);
        $this->assertSame([], NotionModelFake::$deleteCalls);

        Mail::assertSent(SyncReportMail::class, function (SyncReportMail $mail) {
            $mail->build();

            $rendered = $mail->render();

            return $mail->subject === 'Notion同期レポート'
                && $mail->totals === ['Personal Label' => 1]
                && $mail->details === [
                    'Personal Label' => [
                        ['start' => '2024-05-10 09:00', 'summary' => 'Board Meeting'],
                    ],
                ]
                && is_string($rendered)
                && str_contains($rendered, 'Personal Label: 1件')
                && str_contains($rendered, '  - 2024-05-10 09:00 Board Meeting')
                && str_contains($rendered, '以上の予定をNotionデータベースに同期しました。');
        });
    }

    public function test_command_sends_slack_dm_when_enabled(): void
    {
        $this->setDefaultCalendarConfig();
        config()->set('app.sync_report_mail_to', null);
        config()->set('app.slack_bot_enabled', true);
        config()->set('app.slack_bot_token', 'xoxb-test-token');
        config()->set('app.slack_dm_user_ids', 'U0123456789');

        $event = (object) [
            'id' => 'event-1',
            'summary' => 'Board Meeting',
            'start' => (object) ['dateTime' => '2024-05-10T09:00:00+09:00'],
        ];

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

        Http::fake([
            'https://slack.com/api/chat.postMessage' => Http::response([
                'ok' => true,
                'ts' => '1714976400.000100',
            ]),
        ]);

        $this->artisan('command:gcal-sync-notion')
            ->assertExitCode(Command::SUCCESS);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && $request['channel'] === 'U0123456789'
                && $request['text'] === "Personal Label: 1件\n  - 2024-05-10 09:00 Board Meeting\n以上の予定をNotionデータベースに同期しました。";
        });
    }

    public function test_sync_report_special_characters_are_not_escaped_for_slack_or_mail(): void
    {
        $this->setDefaultCalendarConfig();
        config()->set('app.sync_report_mail_to', 'notify@example.com');
        config()->set('app.slack_bot_enabled', true);
        config()->set('app.slack_bot_token', 'xoxb-test-token');
        config()->set('app.slack_dm_user_ids', 'U0123456789');

        $event = (object) [
            'id' => 'event-1',
            'summary' => 'R&D & QA <Review>',
            'start' => (object) ['dateTime' => '2024-05-10T09:00:00+09:00'],
        ];

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

        Http::fake([
            'https://slack.com/api/chat.postMessage' => Http::response([
                'ok' => true,
                'ts' => '1714976400.000100',
            ]),
        ]);

        $this->artisan('command:gcal-sync-notion')
            ->assertExitCode(Command::SUCCESS);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://slack.com/api/chat.postMessage'
                && $request['channel'] === 'U0123456789'
                && $request['text'] === "Personal Label: 1件\n  - 2024-05-10 09:00 R&D & QA <Review>\n以上の予定をNotionデータベースに同期しました。";
        });

        Mail::assertSent(SyncReportMail::class, function (SyncReportMail $mail) {
            $mail->build();

            $rendered = $mail->render();

            return str_contains($rendered, 'R&D & QA <Review>')
                && !str_contains($rendered, 'R&amp;D &amp; QA &lt;Review&gt;');
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

        $event = (object) [
            'id' => 'holiday-event',
            'summary' => '敬老の日',
            'start' => (object) ['date' => '2024-09-15'],
        ];

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

        Mail::assertSent(SyncReportMail::class, function (SyncReportMail $mail) {
            $mail->build();

            $rendered = $mail->render();

            return $mail->subject === 'Notion同期レポート'
                && $mail->totals === ['Holiday Label' => 1]
                && $mail->details === [
                    'Holiday Label' => [
                        ['start' => '2024-09-15', 'summary' => '敬老の日'],
                    ],
                ]
                && is_string($rendered)
                && str_contains($rendered, 'Holiday Label: 1件')
                && str_contains($rendered, '  - 2024-09-15 敬老の日')
                && str_contains($rendered, '以上の予定をNotionデータベースに同期しました。');
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

    public function test_command_skips_notion_events_when_google_calendar_id_missing(): void
    {
        $this->setDefaultCalendarConfig();
        config()->set('app.sync_report_mail_to', 'notify@example.com');

        $notionEvents = collect([
            [
                'id' => 'notion-event-without-id',
                'properties' => [
                    'googleCalendarId' => [
                        'rich_text' => [],
                    ],
                ],
            ],
        ]);

        NotionModelFake::$upcomingEventsReturn = $notionEvents;

        GoogleCalendarModelFake::$eventLists = [
            'calendar-personal' => [],
        ];

        Mail::fake();

        $this->artisan('command:gcal-sync-notion')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, NotionModelFake::$getUpcomingCalls);
        $this->assertSame([], NotionModelFake::$deleteCalls);

        Mail::assertNothingSent();
    }

    public function test_command_skips_calendars_when_id_is_empty_string(): void
    {
        $this->setDefaultCalendarConfig();
        config()->set('app.google_calendar_id_personal', '');
        config()->set('app.sync_report_mail_to', 'notify@example.com');

        NotionModelFake::$upcomingEventsReturn = collect();

        Mail::fake();

        $this->artisan('command:gcal-sync-notion')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame([], GoogleCalendarModelFake::$getListCalls);
        Mail::assertNothingSent();
    }
}
