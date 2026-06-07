<?php

namespace App\Services;

class DocumentChunkingService
{
    public function __construct(
        protected int $targetLength = 900,
        protected int $overlapLength = 150
    ) {
    }

    /**
     * Split a regulation text into readable chunks with a small overlap.
     *
     * @return array<int, string>
     */
    public function chunk(string $text): array
    {
        $text = $this->normalize($text);

        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $this->targetLength) {
            return [$text];
        }

        $units = $this->splitIntoUnits($text);
        $chunks = [];
        $current = '';

        foreach ($units as $unit) {
            if (mb_strlen($unit) > $this->targetLength) {
                foreach ($this->splitLongUnit($unit) as $piece) {
                    $this->appendUnit($chunks, $current, $piece);
                }
                continue;
            }

            $this->appendUnit($chunks, $current, $unit);
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return array_values(array_filter(array_unique($chunks)));
    }

    protected function appendUnit(array &$chunks, string &$current, string $unit): void
    {
        $separator = $current === '' ? '' : "\n\n";
        $candidate = $current . $separator . $unit;

        if ($current !== '' && mb_strlen($candidate) > $this->targetLength) {
            $chunks[] = trim($current);
            $current = $this->buildOverlap($current);
            $separator = $current === '' ? '' : "\n\n";
            $candidate = $current . $separator . $unit;
        }

        $current = trim($candidate);
    }

    protected function normalize(string $text): string
    {
        $text = strip_tags($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text ?? '');
    }

    /**
     * @return array<int, string>
     */
    protected function splitIntoUnits(string $text): array
    {
        $paragraphs = preg_split('/\n{2,}/', $text) ?: [];
        $units = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) <= $this->targetLength) {
                $units[] = $paragraph;
                continue;
            }

            $sentences = preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph];
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if ($sentence !== '') {
                    $units[] = $sentence;
                }
            }
        }

        return $units;
    }

    /**
     * @return array<int, string>
     */
    protected function splitLongUnit(string $unit): array
    {
        $words = preg_split('/\s+/u', trim($unit)) ?: [];
        $pieces = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = trim($current . ' ' . $word);
            if ($current !== '' && mb_strlen($candidate) > $this->targetLength) {
                $pieces[] = $current;
                $current = trim($this->buildOverlap($current) . ' ' . $word);
                continue;
            }
            $current = $candidate;
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    protected function buildOverlap(string $text): string
    {
        if ($this->overlapLength <= 0 || mb_strlen($text) <= $this->overlapLength) {
            return '';
        }

        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $overlap = '';

        while (!empty($words)) {
            $word = array_pop($words);
            $candidate = $overlap === '' ? $word : $word . ' ' . $overlap;
            if (mb_strlen($candidate) > $this->overlapLength) {
                break;
            }
            $overlap = $candidate;
        }

        return trim($overlap);
    }
}
