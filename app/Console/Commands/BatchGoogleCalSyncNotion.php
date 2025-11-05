<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\NotionModel;
use App\Models\GoogleCalendarModel;
use Illuminate\Support\Facades\Mail;
use App\Mail\SyncReportMail;
use App\Services\SlackNotifier;
use App\Support\SyncReportFormatter;

class BatchGoogleCalSyncNotion extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:gcal-sync-notion {mode=default : "holiday"を指定した場合、祝日カレンダーのみを処理する。"default"の場合は祝日カレンダー以外を処理する。}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '複数のGoogleカレンダーをNotionのカレンダーに同期する。追加／削除のみ。更新には対応していない。';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    private function getGoogleCalendarList(): array
    {
        return array (
            'personal' => array (
                'calendar_id' => (string)config('app.google_calendar_id_personal'),
                'notion_label' => (string)config('app.google_calendar_label_personal'),
            ),
            'business' => array(
                'calendar_id' => (string)config('app.google_calendar_id_business'),
                'notion_label' => (string)config('app.google_calendar_label_business'),
            ),
            'school' => array(
                'calendar_id' => (string)config('app.google_calendar_id_school'),
                'notion_label' => (string)config('app.google_calendar_label_school'),
            )
        );
    }

    private function getGoogleHolidayCalendarList(): array
    {
        return array (
            'holiday' => array (
                'calendar_id' => (string)config('app.google_calendar_id_holiday'),
                'notion_label' => (string)config('app.google_calendar_label_holiday'),
            )
        );
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $holidayCalendarList = $this->getGoogleHolidayCalendarList();

        // Mode
        if( $this->argument('mode') === 'holiday'){
            $calendar_list = $holidayCalendarList;
            $deleteNotionTasks = false;
        }else{
            $calendar_list = $this->getGoogleCalendarList();
            $deleteNotionTasks = true;
        }

        $syncCounts = [];
        foreach (array_keys($calendar_list) as $key) {
            $syncCounts[$key] = 0;
        }
        $syncDetails = [];

        $notions = new NotionModel;

        // 設定値(sync_max_days)に従い同期対象の日付を取得
        $targetDateStart = (string)date("Y-m-d");
        $targetDateEnd = (string)date("Y-m-d", strtotime('+'. config('app.sync_max_days') .'day'));
        
        // 除外するジャンルのラベルを取得
        $excludeLabels = array_column($holidayCalendarList, 'notion_label');

        // Notionに登録されている指定範囲のイベントを取得
        if ($deleteNotionTasks) {
            try {
                $notionEvents = $notions->getUpcomingNotionEvents($targetDateStart, $targetDateEnd, $excludeLabels);
            } catch (\Exception $e) {
                report($e);
                return Command::FAILURE;
            }
        }
        
        $googleEventIds = [];

        // 各Google Calenterから指定範囲のイベントを取得
        foreach (array_keys($calendar_list) as $key){
            if(empty($calendar_list[$key]['calendar_id'])){
                continue;
            }

            $events = [];
            $googlecal = new GoogleCalendarModel;

            try{
                $events = $googlecal->getGoogleCalendarEventList( $targetDateStart, $targetDateEnd, $calendar_list[$key]['calendar_id']);
            }catch (\Exception $e){
                report($e);
                return Command::FAILURE;
            };

            foreach ($events as $event) {
                // 参加型のイベントで自分が参加するもの以外はスルー
                if (isset($event->attendees)) {
                    if (!$googlecal->isUserParticipating($event)){
                        continue;
                    }
                }

                // イベントIDを配列に格納
                $googleEventIds[] = $event->id;

                // このイベントがNotionに登録されているか検索
                try{
                    $collections = $notions->getCollectionsFromNotion($event->id);
                }catch(\Exception $e){
                    report($e);
                    return Command::FAILURE;
                }

                // 既にNotionに登録されているのでスルー
                if (count($collections)) {
                    continue;
                }

                // Notionに登録
                try{
                    $registered = $notions->registNotionEvent($event, $calendar_list[$key]['notion_label']);
                }catch(\Exception $e){
                    report($e);
                    return Command::FAILURE;
                }

                if ($registered) {
                    $syncCounts[$key]++;

                    $calendarLabel = $calendar_list[$key]['notion_label'] ?? $key;
                    if (!array_key_exists($calendarLabel, $syncDetails)) {
                        $syncDetails[$calendarLabel] = [];
                    }

                    $syncDetails[$calendarLabel][] = [
                        'start' => $this->formatEventStart($event),
                        'summary' => isset($event->summary) ? (string) $event->summary : '',
                    ];
                }
            }
        }
        
        // googleCalendarIdが設定されているにも関わらずGoogleカレンダーに存在しないイベントをNotionから削除
        // 祝日カレンダーの場合は削除しない
        if ($deleteNotionTasks) {
            foreach ($notionEvents as $notionEvent) {
                $googleCalendarId = null;

                if (isset($notionEvent['properties']['googleCalendarId'])) {
                    $googleCalendarProperty = $notionEvent['properties']['googleCalendarId'];
                    $richText = $googleCalendarProperty['rich_text'] ?? null;

                    if (is_array($richText) && isset($richText[0]) && is_array($richText[0])) {
                        $googleCalendarId = $richText[0]['text']['content'] ?? null;
                    }
                }

                if (!is_string($googleCalendarId) || $googleCalendarId === '') {
                    // googleCalendarId が取得できない場合は削除候補に含めない
                    continue;
                }

                $existsInGoogleCalendar = false;
                foreach ($googleEventIds as $googleEventId) {
                    if ($googleEventId === $googleCalendarId) {
                        $existsInGoogleCalendar = true;
                        break;
                    }
                }

                if (!$existsInGoogleCalendar) {
                    try {
                        $notions->deleteNotionEvent($notionEvent['id']);
                    } catch (\Exception $e) {
                        report($e);
                        return Command::FAILURE;
                    }
                }
            }
        }
        $totalSynced = array_sum($syncCounts);

        if ($totalSynced > 0) {
            $totals = [];
            foreach ($calendar_list as $key => $calendar) {
                $count = $syncCounts[$key] ?? 0;
                if ($count <= 0) {
                    continue;
                }

                $label = $calendar['notion_label'] ?? $key;
                $totals[$label] = $count;
            }

            if (!empty($totals)) {
                $bodyText = SyncReportFormatter::formatText($totals, $syncDetails);

                $slackNotifier = new SlackNotifier();
                $slackMessages = [];
                try {
                    $slackMessages = $slackNotifier->send($bodyText);
                } catch (\Throwable $e) {
                    report($e);
                    return Command::FAILURE;
                }

                $mailTo = config('app.sync_report_mail_to');

                if (!empty($mailTo)) {
                    try {
                        Mail::to($mailTo)->send(new SyncReportMail($totals, $syncDetails));
                    } catch (\Throwable $e) {
                        $slackNotifier->notifyError($slackMessages ?? [], $e);
                        report($e);
                        return Command::FAILURE;
                    }
                }
            }
        }

        return Command::SUCCESS;
    }

    private function formatEventStart(object $event): string
    {
        if (!isset($event->start)) {
            return '';
        }

        $start = $event->start;

        if (isset($start->dateTime) && $start->dateTime !== '') {
            try {
                $dateTime = new \DateTime($start->dateTime);
                return $dateTime->format('Y-m-d H:i');
            } catch (\Exception $e) {
                return (string) $start->dateTime;
            }
        }

        if (isset($start->date) && $start->date !== '') {
            return (string) $start->date;
        }

        return '';
    }
}
