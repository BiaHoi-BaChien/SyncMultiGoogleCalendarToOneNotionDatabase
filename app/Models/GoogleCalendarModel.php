<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Google_Client;
use Google_Service_Calendar;
use Google\Service\Calendar\Event;
use phpDocumentor\Reflection\DocBlock\Tags\Var_;

class GoogleCalendarModel extends Model
{
    use HasFactory;

    /**
    * get google event list
    *
    * @param integer $max_result
    * @param String $google_calendar_id 
    * @return event object
    */     
    public function getGoogleCalendarEventList(string $targetDateStart, string $targetDateEnd, string $google_calendar_id)
    {
        // Googleカレンダーに登録されているデータを取得
        $client = $this->getClient();
        $service = new Google_Service_Calendar($client);

        $optParams = array(
            'maxResults' => 200,
            'orderBy' => 'startTime',
            'singleEvents' => true,
            'timeMin' => date('c', strtotime($targetDateStart ." 00:00:00")),
            'timeMax' => date('c', strtotime($targetDateEnd ." 23:59:59")), 
            'timeZone' => config('app.timezone'),
        );

        $results = $service->events->listEvents($google_calendar_id, $optParams);
        
        return $results->getItems();
    }

    /**
     * 指定したイベントの参加ステータスを確認し、参加する場合はtrue、参加しない場合はfalseを返す
    *
    * @param object $event Googleカレンダーのイベントオブジェクト
    * @return bool 参加する場合はtrue、参加しない場合はfalse
    */
    function isUserParticipating($event) {
        // デフォルトでは未参加と仮定
        $isParticipating = false;

        // イベントにattendeesプロパティが存在するか確認
        if (isset($event->attendees)) {
            // 自分のステータスを確認
            foreach ($event->attendees as $attendee) {
                if (isset($attendee->self) && $attendee->self && isset($attendee->responseStatus)) {
                    $status = $attendee->responseStatus;
                    if ($status === 'accepted' || $status === 'needsAction') {
                        $isParticipating = true;
                    } elseif ($status === 'declined') {
                        $isParticipating = false;
                    }
                    break;
                }
            }
        }

        return $isParticipating;
    }


    /**
    * get client object
    *
    * @return object
    */     
    private  function getClient()
    {
        $client = new Google_Client();

        $client->setApplicationName('Google Calendar API plus Laravel');
        $client->setScopes(Google_Service_Calendar::CALENDAR_READONLY);
        $client->setAuthConfig(storage_path(config('app.google_calendar_path_to_json')));

        return $client;
    }
}
