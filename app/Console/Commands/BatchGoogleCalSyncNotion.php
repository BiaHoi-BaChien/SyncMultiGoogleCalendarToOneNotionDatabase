<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\NotionModel;
use App\Models\GoogleCalendarModel;

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
    private $google_calendar_list;

    public function __construct()
    {
        parent::__construct();

        $this->google_calendar_list = array (
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

        $this->google_holiday_calendar_list = array (
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
        // Mode
        if( $this->argument('mode') === 'holiday'){
            $calendar_list = $this->google_holiday_calendar_list;
            $deleteNotionTasks = false;
        }else{
            $calendar_list = $this->google_calendar_list;
            $deleteNotionTasks = true;
        }

        $notions = new NotionModel;

        // 設定値(sync_max_days)に従い同期対象の日付を取得
        $targetDateStart = (string)date("Y-m-d");
        $targetDateEnd = (string)date("Y-m-d", strtotime('+'. config('app.sync_max_days') .'day'));
        
        // 除外するジャンルのラベルを取得
        $excludeLabels = array_column($this->google_holiday_calendar_list, 'notion_label');

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
            if(is_null($calendar_list[$key]['calendar_id'])){
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
                    $notions->registNotionEvent($event, $calendar_list[$key]['notion_label']);
                }catch(\Exception $e){
                    report($e);
                    return Command::FAILURE;
                }
            }        
        }
        
        // googleCalendarIdが設定されているにも関わらずGoogleカレンダーに存在しないイベントをNotionから削除
        // 祝日カレンダーの場合は削除しない
        if ($deleteNotionTasks) {
            foreach ($notionEvents as $notionEvent) {
                $existsInGoogleCalendar = false;
                foreach ($googleEventIds as $googleEventId) {
                    if ($googleEventId === $notionEvent['properties']['googleCalendarId']['rich_text'][0]['text']['content']) {
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
        return Command::SUCCESS;
    }   
}
