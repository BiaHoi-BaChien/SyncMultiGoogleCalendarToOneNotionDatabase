<?php

namespace Tests\Unit;

use App\Support\SyncReportFormatter;
use Tests\TestCase;

class SyncReportFormatterTest extends TestCase
{
    public function test_format_text_formats_action_with_parentheses(): void
    {
        $text = SyncReportFormatter::formatText([
            '仕事' => 1,
        ], [
            '仕事' => [
                [
                    'action' => '追加',
                    'start' => '2026-01-01 09:00',
                    'summary' => '定例会議',
                ],
            ],
        ]);

        $this->assertStringContainsString('  - (追加) 2026-01-01 09:00 定例会議', $text);
        $this->assertStringNotContainsString('  - 追加 ) 2026-01-01 09:00 定例会議', $text);
    }

    public function test_format_text_displays_delete_action_with_parentheses(): void
    {
        $text = SyncReportFormatter::formatText([
            '個人' => 1,
        ], [
            '個人' => [
                [
                    'action' => '削除',
                    'start' => '2026-01-02 10:00',
                    'summary' => '不要な予定',
                ],
            ],
        ]);

        $this->assertStringContainsString('  - (削除) 2026-01-02 10:00 不要な予定', $text);
    }
}
