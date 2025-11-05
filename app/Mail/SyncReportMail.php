<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SyncReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @var array<string, int>
     */
    public array $totals;

    /**
     * @var array<string, array<int, array{start: string, summary: string}>>
     */
    public array $details;

    /**
     * Create a new message instance.
     *
     * @param array<string, int> $totals
     * @param array<string, array<int, array{start: string, summary: string}>> $details
     */
    public function __construct(array $totals, array $details)
    {
        $this->totals = $totals;
        $this->details = $details;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $lines = [];

        foreach ($this->totals as $label => $count) {
            $lines[] = sprintf('%s: %d件', $label, $count);

            $events = $this->details[$label] ?? [];
            foreach ($events as $event) {
                $start = $event['start'];
                $summary = $event['summary'];
                $detail = trim(trim($start) . ' ' . trim($summary));
                if ($detail === '') {
                    $detail = '(詳細なし)';
                }

                $lines[] = sprintf('  - %s', $detail);
            }
        }

        $lines[] = '以上の予定をNotionデータベースに同期しました。';

        $body = implode(PHP_EOL, $lines);

        return $this
            ->subject('Notion同期レポート')
            ->text('mail.sync_report_plain')
            ->with([
                'body' => $body,
            ]);
    }
}
