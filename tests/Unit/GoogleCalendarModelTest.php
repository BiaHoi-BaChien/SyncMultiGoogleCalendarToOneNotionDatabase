<?php

namespace Tests\Unit;

use App\Models\GoogleCalendarModel;
use stdClass;
use Tests\TestCase;

class GoogleCalendarModelTest extends TestCase
{
    private function makeEventWithAttendees(array $attendees): stdClass
    {
        $event = new stdClass();
        $event->attendees = $attendees;

        return $event;
    }

    public function test_isUserParticipating_returns_true_when_self_accepted(): void
    {
        $attendee = new stdClass();
        $attendee->self = true;
        $attendee->responseStatus = 'accepted';

        $event = $this->makeEventWithAttendees([$attendee]);

        $model = new GoogleCalendarModel();
        $this->assertTrue($model->isUserParticipating($event));
    }

    public function test_isUserParticipating_returns_true_when_self_needs_action(): void
    {
        $attendee = new stdClass();
        $attendee->self = true;
        $attendee->responseStatus = 'needsAction';

        $event = $this->makeEventWithAttendees([$attendee]);

        $model = new GoogleCalendarModel();
        $this->assertTrue($model->isUserParticipating($event));
    }

    public function test_isUserParticipating_returns_false_when_self_declined(): void
    {
        $attendee = new stdClass();
        $attendee->self = true;
        $attendee->responseStatus = 'declined';

        $event = $this->makeEventWithAttendees([$attendee]);

        $model = new GoogleCalendarModel();
        $this->assertFalse($model->isUserParticipating($event));
    }

    public function test_isUserParticipating_returns_false_when_attendees_missing(): void
    {
        $event = new stdClass();

        $model = new GoogleCalendarModel();
        $this->assertFalse($model->isUserParticipating($event));
    }

    public function test_isUserParticipating_returns_false_when_self_flag_missing(): void
    {
        $attendee = new stdClass();
        $attendee->responseStatus = 'accepted';

        $event = $this->makeEventWithAttendees([$attendee]);

        $model = new GoogleCalendarModel();
        $this->assertFalse($model->isUserParticipating($event));
    }

    public function test_isUserParticipating_checks_each_attendee_until_self_found(): void
    {
        $other = new stdClass();
        $other->self = false;
        $other->responseStatus = 'accepted';

        $self = new stdClass();
        $self->self = true;
        $self->responseStatus = 'accepted';

        $event = $this->makeEventWithAttendees([$other, $self]);

        $model = new GoogleCalendarModel();
        $this->assertTrue($model->isUserParticipating($event));
    }
}
