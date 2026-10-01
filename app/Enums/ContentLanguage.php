<?php

namespace App\Enums;

enum ContentLanguage: string
{
    case English = 'en';
    case Ukrainian = 'uk';
    case German = 'de';

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Ukrainian => 'Ukrainian',
            self::German => 'German',
        };
    }
}
