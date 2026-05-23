<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ollama Connection Settings
    |--------------------------------------------------------------------------
    */

    'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
    'model' => env('OLLAMA_MODEL', 'mistral'),
    'fallback_model' => env('OLLAMA_FALLBACK_MODEL', 'gemma3:4b'),
    'embedding_model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'),
    'api_key' => env('OLLAMA_API_KEY', 'ollama'),
    'timeout' => (int) env('OLLAMA_TIMEOUT', 180),
    'retry_attempts' => (int) env('OLLAMA_RETRY_ATTEMPTS', 3),
    'retry_delay' => (int) env('OLLAMA_RETRY_DELAY', 500),

    /*
    |--------------------------------------------------------------------------
    | AI Generation Parameters
    |--------------------------------------------------------------------------
    */

    'temperature' => (float) env('AI_TEMPERATURE', 0.2),
    'top_p' => (float) env('AI_TOP_P', 0.7),
    'repeat_penalty' => (float) env('AI_REPEAT_PENALTY', 1.5),
    'num_predict' => (int) env('AI_NUM_PREDICT', 350),

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

    'system_prompt' => "Kamu adalah Lentra AI, asisten hukum lalu lintas Indonesia.\n\n"
        . "TOPIK YANG BOLEH DIJAWAB (HANYA INI):\n"
        . "- Hukum dan peraturan lalu lintas Indonesia (UU No. 22 Tahun 2009)\n"
        . "- SIM, STNK, helm, tilang, rambu, parkir, kecelakaan, kecepatan\n"
        . "- Sanksi dan denda pelanggaran lalu lintas\n"
        . "- Edukasi berkendara yang aman\n\n"
        . "JIKA PERTANYAAN DI LUAR TOPIK LALU LINTAS:\n"
        . "Jawab HANYA dengan kalimat ini (jangan tambahkan info lain):\n"
        . "\"Maaf, saya hanya dapat membantu pertanyaan seputar hukum lalu lintas Indonesia. Silakan tanyakan tentang SIM, STNK, helm, tilang, atau aturan berkendara.\"\n\n"
        . "ATURAN JAWABAN:\n"
        . "- Jawab SEKALI saja, tidak boleh mengulang poin yang sama\n"
        . "- Maksimal 3-4 kalimat untuk topik sederhana\n"
        . "- Singkat, jelas, langsung ke inti\n"
        . "- Gunakan bahasa Indonesia formal\n"
        . "- Jangan buat pasal palsu\n"
        . "- Jangan menulis ulang pertanyaan pengguna di jawaban\n"
        . "- Setelah menyebut sanksi/denda, BERHENTI — jangan tambah kalimat penutup berulang",

];
