<?php

namespace App\Services;

class InputPreprocessor
{
    /**
     * Kata kunci terkait hukum lalu lintas untuk deteksi relevansi.
     */
    protected array $trafficKeywords = [
        'helm', 'tilang', 'stnk', 'sim', 'parkir', 'kecelakaan', 'lampu merah',
        'rambu', 'kecepatan', 'sabuk', 'mabuk', 'narkoba', 'asuransi', 'kendaraan',
        'motor', 'mobil', 'truk', 'bus', 'sepeda', 'jalan', 'lalu lintas', 'uu',
        'undang', 'pasal', 'sanksi', 'denda', 'pidana', 'polisi', 'penegak',
        'registrasi', 'surat', 'izin', 'mengemudi', 'pengemudi',
    ];

    /**
     * Daftar singkatan umum yang akan dinormalisasi.
     */
    protected array $abbreviations = [
        ' yg ' => ' yang ',
        ' dgn ' => ' dengan ',
        ' tdk ' => ' tidak ',
        ' blm ' => ' belum ',
        ' krn ' => ' karena ',
        ' utk ' => ' untuk ',
        ' dlm ' => ' dalam ',
        ' thn ' => ' tahun ',
        ' bsa ' => ' bisa ',
        ' klo ' => ' kalau ',
        ' kl ' => ' kalau ',
        ' klw ' => ' kalau ',
        ' gmn ' => ' bagaimana ',
        ' bgmn ' => ' bagaimana ',
    ];

    /**
     * Membersihkan dan menormalisasi input teks pengguna (PBI-3).
     *
     * @param string $text
     * @return string
     */
    public function clean(string $text): string
    {
        // 1. Hapus tag HTML / XSS dasar
        $text = strip_tags($text);

        // 2. Hapus karakter kontrol (kecuali newline & tab)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        // 3. Normalisasi multiple whitespace/newline menjadi satu
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);

        // 4. Normalisasi singkatan umum
        $text = $this->normalizeAbbreviations($text);

        // 5. Trim spasi di awal dan akhir
        return trim($text);
    }

    /**
     * Memeriksa relevansi teks dengan domain hukum lalu lintas.
     *
     * @param string $text
     * @return array
     */
    public function checkRelevance(string $text): array
    {
        $lowerMsg = mb_strtolower($text);
        $matchedWords = [];
        $isRelevant = false;

        foreach ($this->trafficKeywords as $kw) {
            // Gunakan preg_match untuk memastikan pencocokan kata yang tepat (opsional, tapi disarankan)
            // Namun karena kita mentolerir imbuhan, str_contains sudah cukup baik
            if (str_contains($lowerMsg, $kw)) {
                $isRelevant = true;
                $matchedWords[] = $kw;
            }
        }

        return [
            'is_relevant' => $isRelevant,
            'matched_words' => array_unique($matchedWords),
        ];
    }

    /**
     * Normalisasi singkatan umum menjadi kata penuh.
     * 
     * @param string $text
     * @return string
     */
    protected function normalizeAbbreviations(string $text): string
    {
        // Tambahkan padding spasi untuk mempermudah replace
        $paddedText = ' ' . $text . ' ';
        
        foreach ($this->abbreviations as $abbr => $fullWord) {
            // Case-insensitive replace
            $paddedText = str_ireplace($abbr, $fullWord, $paddedText);
        }

        return trim($paddedText);
    }
}
