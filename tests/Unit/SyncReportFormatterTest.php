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
}
