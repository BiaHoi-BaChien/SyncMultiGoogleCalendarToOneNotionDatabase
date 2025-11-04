<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

class BatchGoogleCalSyncNotionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
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

        $notionMock = Mockery::mock('overload:App\\Models\\NotionModel');
        $notionMock->shouldReceive('getUpcomingNotionEvents')
            ->once()
            ->andReturn(collect());
        $notionMock->shouldReceive('getCollectionsFromNotion')
            ->once()
            ->with('event-1')
            ->andReturn(collect());
        $notionMock->shouldReceive('registNotionEvent')
            ->once()
            ->with($event, 'Personal Label')
            ->andReturnTrue();
        $notionMock->shouldNotReceive('deleteNotionEvent');

        $googleMock = Mockery::mock('overload:App\\Models\\GoogleCalendarModel');
        $googleMock->shouldReceive('getGoogleCalendarEventList')
            ->once()
            ->andReturn([$event]);
        $googleMock->shouldNotReceive('isUserParticipating');

        Mail::fake();

        $this->artisan('command:gcal-sync-notion')
            ->assertExitCode(Command::SUCCESS);

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

        $notionMock = Mockery::mock('overload:App\\Models\\NotionModel');
        $notionMock->shouldReceive('getUpcomingNotionEvents')
            ->once()
            ->andReturn(collect());
        $notionMock->shouldReceive('getCollectionsFromNotion')
            ->once()
            ->with('event-1')
            ->andReturn(collect(['existing']));
        $notionMock->shouldNotReceive('registNotionEvent');
        $notionMock->shouldNotReceive('deleteNotionEvent');

        $googleMock = Mockery::mock('overload:App\\Models\\GoogleCalendarModel');
        $googleMock->shouldReceive('getGoogleCalendarEventList')
            ->once()
            ->andReturn([$event]);
        $googleMock->shouldNotReceive('isUserParticipating');

        Mail::fake();

        $this->artisan('command:gcal-sync-notion')
            ->assertExitCode(Command::SUCCESS);

        Mail::assertNothingSent();
    }

    public function test_command_does_not_delete_events_in_holiday_mode(): void
    {
        $this->setDefaultCalendarConfig();
        config()->set('app.sync_report_mail_to', 'notify@example.com');

        $event = (object) ['id' => 'holiday-event'];

        $notionMock = Mockery::mock('overload:App\\Models\\NotionModel');
        $notionMock->shouldReceive('getCollectionsFromNotion')
            ->once()
            ->with('holiday-event')
            ->andReturn(collect());
        $notionMock->shouldReceive('registNotionEvent')
            ->once()
            ->with($event, 'Holiday Label')
            ->andReturnTrue();
        $notionMock->shouldNotReceive('getUpcomingNotionEvents');
        $notionMock->shouldNotReceive('deleteNotionEvent');

        $googleMock = Mockery::mock('overload:App\\Models\\GoogleCalendarModel');
        $googleMock->shouldReceive('getGoogleCalendarEventList')
            ->once()
            ->andReturn([$event]);
        $googleMock->shouldNotReceive('isUserParticipating');

        Mail::fake();

        $this->artisan('command:gcal-sync-notion', ['mode' => 'holiday'])
            ->assertExitCode(Command::SUCCESS);

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

        $notionMock = Mockery::mock('overload:App\\Models\\NotionModel');
        $notionMock->shouldReceive('getUpcomingNotionEvents')
            ->once()
            ->andReturn($notionEvents);
        $notionMock->shouldReceive('getCollectionsFromNotion')
            ->once()
            ->with('event-existing')
            ->andReturn(collect(['existing']));
        $notionMock->shouldNotReceive('registNotionEvent');
        $notionMock->shouldReceive('deleteNotionEvent')
            ->once()
            ->with('notion-event-1');

        $googleMock = Mockery::mock('overload:App\\Models\\GoogleCalendarModel');
        $googleMock->shouldReceive('getGoogleCalendarEventList')
            ->once()
            ->andReturn([(object) ['id' => 'event-existing']]);
        $googleMock->shouldNotReceive('isUserParticipating');

        Mail::fake();

        $this->artisan('command:gcal-sync-notion')
            ->assertExitCode(Command::SUCCESS);

        Mail::assertNothingSent();
    }
}
