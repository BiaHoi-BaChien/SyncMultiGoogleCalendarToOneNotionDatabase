<?php

namespace App\Support;

class SyncReportFormatter
{
    /**
     * @param array<string, int> $totals
     * @param array<string, array<int, array{start: string, summary: string}>> $details
     */
    public static function formatText(array $totals, array $details): string
    {
        $lines = [];

        foreach ($totals as $label => $count) {
            $lines[] = sprintf('%s: %d件', $label, $count);

            $events = $details[$label] ?? [];
            foreach ($events as $event) {
                $start = $event['start'] ?? '';
                $summary = $event['summary'] ?? '';
                $detail = trim(trim((string) $start) . ' ' . trim((string) $summary));
                if ($detail === '') {
                    $detail = '(詳細なし)';
                }

                $lines[] = sprintf('  - %s', $detail);
            }
        }

        $lines[] = '以上の予定をNotionデータベースに同期しました。';

        return implode(PHP_EOL, $lines);
    }
}
