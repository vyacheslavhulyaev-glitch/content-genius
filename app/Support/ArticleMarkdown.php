<?php

namespace App\Support;

use League\CommonMark\CommonMarkConverter;

final class ArticleMarkdown
{
    public function render(?string $markdown): ?string
    {
        if ($markdown === null) {
            return null;
        }

        // Only parser-generated HTML reaches the article presentation, never provider HTML.
        $converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);

        return (string) $converter->convert($markdown);
    }
}
