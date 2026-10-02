<?php

namespace Tests\Support;

use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\Enums\OverrideStrategy;

final class SeoArticleResponse
{
    public static function markdown(string $title = 'Generated text'): string
    {
        return '# '.trim($title)."\n\n## Overview\n\nUseful article body.\n\n### Details\n\nPractical information for readers.";
    }

    public static function payload(string $title = 'Generated text'): array
    {
        return [
            'article_title' => trim($title), 'article_markdown' => self::markdown($title),
            'meta_title' => 'Generated SEO title', 'meta_description' => 'Generated SEO description for readers.',
        ];
    }

    public static function fake(array $attributes = [], OverrideStrategy $strategy = OverrideStrategy::Merge): CreateResponse
    {
        if (! array_key_exists('choices', $attributes)) {
            $attributes['choices'] = [['message' => ['content' => json_encode(self::payload())]]];
        } else {
            foreach ($attributes['choices'] as &$choice) {
                $text = $choice['message']['content'] ?? null;
                if (is_string($text) && trim($text) !== '') {
                    $choice['message']['content'] = json_encode(self::payload($text));
                }
            }
        }

        return CreateResponse::fake($attributes, strategy: $strategy);
    }
}
