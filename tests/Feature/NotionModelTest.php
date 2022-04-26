<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use \App\Models\NotionModel;

class NotionModelTest extends TestCase
{
    /**
     * test for private function validateTodayString.
     *
     * @return void
     */
    public function test_validateTargetDate()
    {
        $notion = new NotionModel;
        $method = new \ReflectionMethod($notion, 'validateTargetDate');
        $method->setAccessible(true);
        $result = $method->invokeArgs($notion, [20211129]);
        $this->assertTrue($result);

        $result = $method->invokeArgs($notion, [123]);
        $this->assertFalse($result);

        // $result = $method->invokeArgs($notion, ["abcdefgh"]);
        // $this->assertFalse($result);
    }

    /**
     *  test for private function getPageId.
     * 
     * @return void
     */
    public function test_getPageId()
    {
        $notion = new NotionModel;
        $method = new \ReflectionMethod($notion, 'getPageId');
        $method->setAccessible(true);

        $result = $method->invokeArgs($notion, ["https://www.notion.so/aaabbbccc"]);
        $this->assertEquals($result, "aaabbbccc");

        $result = $method->invokeArgs($notion, ["https://www.notion.so/A-B-aaabbbccc"]);
        $this->assertEquals($result, "aaabbbccc");

        $result = $method->invokeArgs($notion, ["https://www.notion.so/A-aaabbbccc"]);
        $this->assertEquals($result, "aaabbbccc");

        $result = $method->invokeArgs($notion, ["aaabbbccc"]);
        $this->assertEquals($result, null);        
    }
}
