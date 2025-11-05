<?php

namespace App\Mail;

use App\Support\SyncReportFormatter;
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
        $body = SyncReportFormatter::formatText($this->totals, $this->details);

        return $this
            ->subject('Notion同期レポート')
            ->text('mail.sync_report_plain')
            ->with([
                'body' => $body,
            ]);
    }
}
