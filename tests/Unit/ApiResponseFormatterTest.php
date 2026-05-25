<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ApiResponseFormatter;
use Carbon\Carbon;

class ApiResponseFormatterTest extends TestCase
{
    /**
     * Test that formatSuccess generates correct success response format with dynamic timestamp.
     */
    public function test_format_success_returns_correct_structure(): void
    {
        $message = "Ini adalah jawaban AI.";
        $model = "gemma3";
        $additional = [
            'session_title' => 'Test Session',
            'user_message' => ['id' => 1, 'role' => 'user'],
        ];

        Carbon::setTestNow(Carbon::create(2026, 5, 25, 8, 0, 0));

        $result = ApiResponseFormatter::formatSuccess($message, $model, $additional);

        Carbon::setTestNow(null); // reset time mock

        $this->assertTrue($result['success']);
        $this->assertEquals($message, $result['message']);
        $this->assertEquals($model, $result['model']);
        $this->assertEquals('2026-05-25T08:00:00+00:00', Carbon::parse($result['timestamp'])->toIso8601String());
        $this->assertEquals('Test Session', $result['session_title']);
        $this->assertEquals(1, $result['user_message']['id']);
    }

    /**
     * Test that formatError generates correct error response format.
     */
    public function test_format_error_returns_correct_structure(): void
    {
        $message = "AI sedang tidak tersedia";
        $errorDetail = "Ollama connection timeout after 30s";

        $result = ApiResponseFormatter::formatError($message, $errorDetail);

        $this->assertFalse($result['success']);
        $this->assertEquals($message, $result['message']);
        $this->assertEquals($errorDetail, $result['error']);
    }
}
