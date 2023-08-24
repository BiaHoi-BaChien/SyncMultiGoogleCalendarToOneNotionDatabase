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
    protected $signature = 'command:gcal-sync-notion {mode=default : "holiday"を指定した場合、休日カレンダーのみを処理する。"default"の場合は休日カレンダー以外を処理する。}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '複数のGoogleカレンダーをNotionのカレンダーに同期する。追加のみ。更新／削除には対応していない。';

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
                'notion_label' => '生活',
            ),
            'business' => array(
                'calendar_id' => (string)config('app.google_calendar_id_business'),
                'notion_label' => '仕事',
            ),
            'school' => array(
                'calendar_id' => (string)config('app.google_calendar_id_school'),
                'notion_label' => '学校',
            )
        );

        $this->google_holiday_calendar_list = array (
            'holiday' => array (
                'calendar_id' => (string)config('app.google_calendar_id_holiday'),
                'notion_label' => '祝日',
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
        }else{
            $calendar_list = $this->google_calendar_list;
        }

        $notions = new NotionModel;

        // 設定値(sync_max_days)に従い日数分のデータを同期させる
        $targetDateStart = (string)date("Y-m-d");
        $targetDateEnd = (string)date("Y-m-d", strtotime('+'. config('app.sync_max_days') .'day'));

        // Google Calenterから指定日のデータを取得
        foreach (array_keys($calendar_list) as $key){
            if(is_null($calendar_list[$key]['calendar_id'])){
                continue;
            }                

            $events = array();  
            $googlecal = new GoogleCalendarModel;

            try{
                $events = $googlecal->getGoogleCalendarEventList( $targetDateStart, $targetDateEnd, $calendar_list[$key]['calendar_id']);
            }catch (\Exception $e){
                report($e);
                return Command::FAILURE;
            };

            foreach ($events as $event) {

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
                    $notions->registNotion($event, $calendar_list[$key]['notion_label']); // insert
                }catch(\Exception $e){
                    report($e);
                    return Command::FAILURE;
                }
            }        
        }

        return Command::SUCCESS;
    }   
}
