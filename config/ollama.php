<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ollama Connection Settings
    |--------------------------------------------------------------------------
    */

    'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
    'model' => env('OLLAMA_MODEL', 'mistral'),
    'fallback_model' => env('OLLAMA_FALLBACK_MODEL', 'llama3'),
    'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
    'api_key' => env('OLLAMA_API_KEY', 'ollama'),
    'timeout' => (int) env('OLLAMA_TIMEOUT', 120),
    'retry_attempts' => (int) env('OLLAMA_RETRY_ATTEMPTS', 3),
    'retry_delay' => (int) env('OLLAMA_RETRY_DELAY', 500),

    /*
    |--------------------------------------------------------------------------
    | AI Generation Parameters
    |--------------------------------------------------------------------------
    */

    'temperature' => (float) env('AI_TEMPERATURE', 0.2),
    'top_p' => (float) env('AI_TOP_P', 0.7),
    'repeat_penalty' => (float) env('AI_REPEAT_PENALTY', 1.3),
    'num_predict' => (int) env('AI_NUM_PREDICT', 300),

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limit' => (int) env('CHAT_RATE_LIMIT', 10),

    /*
    |--------------------------------------------------------------------------
    | System Prompt — Identitas Lentra AI
    |--------------------------------------------------------------------------
    */

    'system_prompt' => "Kamu adalah Lentra AI.\n\n"
        . "AI khusus hukum lalu lintas Indonesia.\n\n"
        . "Tugas kamu:\n"
        . "- Menjawab pertanyaan hukum lalu lintas Indonesia\n"
        . "- Memberikan edukasi berkendara\n"
        . "- Menjelaskan pelanggaran dan sanksi lalu lintas\n"
        . "- Membantu pengguna memahami aturan berkendara\n\n"
        . "Aturan wajib:\n"
        . "- Jawaban singkat, jelas, formal, dan mudah dipahami\n"
        . "- Jangan mengulang jawaban\n"
        . "- Jangan keluar topik\n"
        . "- Fokus hanya hukum lalu lintas Indonesia\n"
        . "- Jika pertanyaan di luar topik, tolak dengan sopan\n"
        . "- Jika informasi tidak diketahui, katakan data belum tersedia\n"
        . "- Gunakan bahasa Indonesia formal\n"
        . "- Jangan membuat pasal palsu\n"
        . "- Prioritaskan jawaban berdasarkan UU lalu lintas Indonesia",

];
