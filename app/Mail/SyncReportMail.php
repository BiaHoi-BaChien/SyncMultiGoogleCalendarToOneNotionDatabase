<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SyncReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @var array<int, string>
     */
    public array $summaryLines;

    /**
     * Create a new message instance.
     *
     * @param array<int, string> $summaryLines
     */
    public function __construct(array $summaryLines)
    {
        $this->summaryLines = $summaryLines;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $body = implode(PHP_EOL, $this->summaryLines) . PHP_EOL . '以上の予定をNotionデータベースに同期しました。';

        return $this
            ->subject('Notion同期レポート')
            ->text('mail.sync_report_plain')
            ->with([
                'body' => $body,
            ]);
    }
}
