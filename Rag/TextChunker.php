<?php

declare(strict_types=1);

namespace Genaker\Bundle\OroAI\Rag;

final class TextChunker
{
    private const int MAX_CHUNK_LENGTH = 500;

    /** @return string[] */
    public static function chunk(string $text, int $maxLength = self::MAX_CHUNK_LENGTH): array
    {
        $paragraphs = preg_split('/\n{2,}/', $text);
        if ($paragraphs === false) {
            return [trim($text)];
        }

        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) + 2 > $maxLength) {
                $chunks[] = $current;
                $current = $paragraph;
            } else {
                $current = $current === '' ? $paragraph : $current . "\n\n" . $paragraph;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks === [] ? [trim($text)] : $chunks;
    }
}
