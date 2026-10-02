<?php

namespace App\Support;

final class ArticleLength
{
    public static function words(?string $value): ?int
    {
        $value = mb_strtolower(trim($value ?? ''));
        if ($value === '') {
            return max((int) config('generation.limits.min_words'),
                min((int) config('generation.limits.max_words'), (int) config('generation.limits.default_words')));
        }
        $legacy = ['short' => 250, 'medium' => 800, 'long' => 1200];
        if (isset($legacy[$value])) {
            return $legacy[$value];
        }
        if (preg_match('/^(?:(?:approximately|about|приблизно|близько|etwa|ca\.?)\s+)?([0-9]{1,5})(?:\s*(?:words?|слів|слова|wörter|woerter))?$/u', $value, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    public static function allowed(?string $value): bool
    {
        $words = self::words($value);

        return $words !== null && $words >= config('generation.limits.min_words') && $words <= config('generation.limits.max_words');
    }
}
