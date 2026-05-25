<?php

namespace App\Services;

/**
 * Class ApiResponseFormatter
 *
 * Menghasilkan format response JSON yang konsisten untuk sistem LENTRA AI (PBI-10).
 */
class ApiResponseFormatter
{
    /**
     * Format response sukses final.
     *
     * @param string $message
     * @param string $model
     * @param array  $additional
     * @return array{success: true, message: string, model: string, timestamp: string}
     */
    public static function formatSuccess(string $message, string $model, array $additional = []): array
    {
        return array_merge([
            'success'   => true,
            'message'   => $message,
            'model'     => $model,
            'timestamp' => now()->toIso8601String(),
        ], $additional);
    }

    /**
     * Format response error final.
     *
     * @param string $message
     * @param string $error
     * @return array{success: false, message: string, error: string}
     */
    public static function formatError(string $message, string $error): array
    {
        return [
            'success' => false,
            'message' => $message,
            'error'   => $error,
        ];
    }
}
