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

        $syncCountsByLabel = [];
        $deleteCountsByLabel = [];
        $syncDetails = [];

        $notions = new NotionModel;

        // 設定値(sync_max_days)に従い同期対象の日付を取得
        $targetDateStart = (string)date("Y-m-d");
        $targetDateEnd = (string)date("Y-m-d", strtotime('+'. config('app.sync_max_days') .'day'));

        // 除外するジャンルのラベルを取得
        $excludeLabels = array_column($holidayCalendarList, 'notion_label');

        // Notionに登録されている指定範囲のイベントを取得
        try {
            $notionEvents = $notions->getUpcomingNotionEvents(
                $targetDateStart,
                $targetDateEnd,
                $deleteNotionTasks ? $excludeLabels : []
            );
        } catch (\Exception $e) {
            report($e);
            return Command::FAILURE;
        }

        $registeredNotionEventIndex = $this->buildRegisteredNotionEventIndex($notionEvents);

        // 各Google Calenterから指定範囲のイベントを取得
        foreach (array_keys($calendar_list) as $key){
            if(empty($calendar_list[$key]['calendar_id'])){
                continue;
            }

            $googleEventIds = [];
            $googleEventPeriodsById = [];
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
                $googleEventPeriodsById[$event->id] = $this->formatEventPeriod($event);
                $googleEventPeriod = $googleEventPeriodsById[$event->id];

                // 既にNotionに登録されているのでスルー
                if ($this->isRegisteredInNotion(
                    $registeredNotionEventIndex,
                    $event->id,
                    (string) ($calendar_list[$key]['notion_label'] ?? ''),
                    $googleEventPeriod['start'],
                    $googleEventPeriod['end']
                )) {
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
                    $calendarLabel = $calendar_list[$key]['notion_label'] ?? $key;
                    $registeredNotionEventIndex[$this->buildRegisteredNotionEventIndexKey(
                        (string) $calendarLabel,
                        $event->id,
                        $googleEventPeriod['start'],
                        $googleEventPeriod['end']
                    )] = true;

                    $syncCountsByLabel[$calendarLabel] = ($syncCountsByLabel[$calendarLabel] ?? 0) + 1;
                    if (!array_key_exists($calendarLabel, $syncDetails)) {
                        $syncDetails[$calendarLabel] = [];
                    }

                    $syncDetails[$calendarLabel][] = [
                        'action' => '追加',
                        'start' => $this->formatEventStart($event),
                        'summary' => isset($event->summary) ? (string) $event->summary : '',
                    ];
                }
            }

            // googleCalendarIdが設定されているにも関わらずGoogleカレンダーに存在しないイベントをNotionから削除
            // 祝日カレンダーの場合は削除しない
            if ($deleteNotionTasks) {
                $targetLabel = $calendar_list[$key]['notion_label'] ?? '';
                $filteredNotionEvents = collect($notionEvents)
                    ->filter(function (array $notionEvent) use ($targetLabel) {
                        $label = $this->extractNotionLabel($notionEvent);

                        return $label === '' || $label === $targetLabel;
                    });

                foreach ($filteredNotionEvents as $notionEvent) {
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

                    $googleEventPeriod = $googleEventPeriodsById[$googleCalendarId] ?? null;
                    $notionEventPeriod = $this->formatNotionEventPeriod($notionEvent);

                    if (!in_array($googleCalendarId, $googleEventIds, true)
                        || $googleEventPeriod === null
                        || $googleEventPeriod['start'] !== $notionEventPeriod['start']
                        || $googleEventPeriod['end'] !== $notionEventPeriod['end']) {
                        try {
                            $deleted = $notions->deleteNotionEvent($notionEvent['id']);
                        } catch (\Exception $e) {
                            report($e);
                            return Command::FAILURE;
                        }

                        if ($deleted) {
                            $calendarLabel = $targetLabel !== '' ? $targetLabel : ($key ?? '');
                            $deleteCountsByLabel[$calendarLabel] = ($deleteCountsByLabel[$calendarLabel] ?? 0) + 1;
                            if (!array_key_exists($calendarLabel, $syncDetails)) {
                                $syncDetails[$calendarLabel] = [];
                            }

                            $syncDetails[$calendarLabel][] = [
                                'action' => '削除',
                                'start' => $notionEventPeriod['start'],
                                'summary' => $this->extractNotionSummary($notionEvent),
                            ];
                        }
                    }
                }
            }
        }

        $totalActions = array_sum($syncCountsByLabel) + array_sum($deleteCountsByLabel);

        if ($totalActions > 0) {
            $totals = [];
            foreach (array_unique(array_merge(array_keys($syncCountsByLabel), array_keys($deleteCountsByLabel))) as $label) {
                $totals[$label] = ($syncCountsByLabel[$label] ?? 0) + ($deleteCountsByLabel[$label] ?? 0);
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

    private function formatEventPeriod(object $event): array
    {
        $start = $this->formatEventStart($event);
        $end = $this->formatEventEnd($event, $start);

        return [
            'start' => $start,
            'end' => $end,
        ];
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

    private function formatEventEnd(object $event, string $fallbackStart = ''): string
    {
        if (!isset($event->end)) {
            return $fallbackStart;
        }

        $end = $event->end;

        if (isset($end->dateTime) && $end->dateTime !== '') {
            try {
                $dateTime = new \DateTime($end->dateTime);
                return $dateTime->format('Y-m-d H:i');
            } catch (\Exception $e) {
                return (string) $end->dateTime;
            }
        }

        if (isset($end->date) && $end->date !== '') {
            try {
                $date = new \DateTime($end->date, new \DateTimeZone(config('app.timezone')));
                $date->sub(new \DateInterval('P1D'));
                return $date->format('Y-m-d');
            } catch (\Exception $e) {
                return (string) $end->date;
            }
        }

        return $fallbackStart;
    }

    private function extractNotionLabel(array $notionEvent): string
    {
        $genre = $notionEvent['properties']['ジャンル']['multi_select'][0]['name'] ?? null;

        return is_string($genre) ? $genre : '';
    }

    private function extractNotionLabels(array $notionEvent): array
    {
        $multiSelect = $notionEvent['properties']['ジャンル']['multi_select'] ?? [];
        if (!is_array($multiSelect)) {
            return [];
        }

        $labels = [];
        foreach ($multiSelect as $genre) {
            $label = $genre['name'] ?? null;
            if (is_string($label) && $label !== '') {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    private function extractGoogleCalendarId(array $notionEvent): ?string
    {
        $richText = $notionEvent['properties']['googleCalendarId']['rich_text'] ?? null;

        if (is_array($richText) && isset($richText[0]) && is_array($richText[0])) {
            $googleCalendarId = $richText[0]['text']['content'] ?? null;

            return is_string($googleCalendarId) && $googleCalendarId !== '' ? $googleCalendarId : null;
        }

        return null;
    }

    private function buildRegisteredNotionEventIndex(iterable $notionEvents): array
    {
        $index = [];

        foreach ($notionEvents as $notionEvent) {
            if (!is_array($notionEvent)) {
                continue;
            }

            $googleCalendarId = $this->extractGoogleCalendarId($notionEvent);
            if ($googleCalendarId === null) {
                continue;
            }

            $period = $this->formatNotionEventPeriod($notionEvent);
            $labels = $this->extractNotionLabels($notionEvent);
            foreach ($labels as $label) {
                $index[$this->buildRegisteredNotionEventIndexKey($label, $googleCalendarId, $period['start'], $period['end'])] = true;
            }

            if (empty($labels)) {
                $index[$this->buildRegisteredNotionEventIndexKey('', $googleCalendarId, $period['start'], $period['end'])] = true;
            }
        }

        return $index;
    }

    private function isRegisteredInNotion(
        array $registeredNotionEventIndex,
        string $googleCalendarId,
        string $notionLabel,
        string $start,
        string $end
    ): bool
    {
        if ($notionLabel === '') {
            $suffix = "\0" . $googleCalendarId . "\0" . $start . "\0" . $end;
            foreach (array_keys($registeredNotionEventIndex) as $key) {
                if (str_ends_with($key, $suffix)) {
                    return true;
                }
            }

            return false;
        }

        return isset($registeredNotionEventIndex[$this->buildRegisteredNotionEventIndexKey($notionLabel, $googleCalendarId, $start, $end)]);
    }

    private function buildRegisteredNotionEventIndexKey(string $notionLabel, string $googleCalendarId, string $start, string $end): string
    {
        return $notionLabel . "\0" . $googleCalendarId . "\0" . $start . "\0" . $end;
    }

    private function extractNotionSummary(array $notionEvent): string
    {
        $title = $notionEvent['properties']['Name']['title'][0]['plain_text'] ?? null;

        return is_string($title) ? $title : '';
    }

    private function formatNotionEventPeriod(array $notionEvent): array
    {
        $start = $this->formatNotionEventStart($notionEvent);
        $end = $this->formatNotionEventEnd($notionEvent, $start);

        return [
            'start' => $start,
            'end' => $end,
        ];
    }

    private function formatNotionEventStart(array $notionEvent): string
    {
        $start = $notionEvent['properties']['Date']['date']['start'] ?? null;
        if (!is_string($start) || $start === '') {
            return '';
        }

        try {
            $dateTime = new \DateTime($start, new \DateTimeZone(config('app.timezone')));
            $hasTime = str_contains($start, 'T');

            return $dateTime->format($hasTime ? 'Y-m-d H:i' : 'Y-m-d');
        } catch (\Exception $e) {
            return $start;
        }
    }

    private function formatNotionEventEnd(array $notionEvent, string $fallbackStart = ''): string
    {
        $end = $notionEvent['properties']['Date']['date']['end'] ?? null;
        if (!is_string($end) || $end === '') {
            return $fallbackStart;
        }

        try {
            $dateTime = new \DateTime($end, new \DateTimeZone(config('app.timezone')));
            $hasTime = str_contains($end, 'T');

            return $dateTime->format($hasTime ? 'Y-m-d H:i' : 'Y-m-d');
        } catch (\Exception $e) {
            return $end;
        }
    }
}
