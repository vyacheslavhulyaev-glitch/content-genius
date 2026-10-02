<?php

return [
    'quota' => [
        'requests' => (int) env('AI_REQUESTS_PER_HOUR', 5),
        'window_seconds' => (int) env('AI_QUOTA_WINDOW_SECONDS', 3600),
    ],
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 4500),
    'limits' => [
        'title' => 180,
        'topic' => 1000,
        'tone' => 80,
        'length' => 40,
        'primary_keyword' => 120,
        'secondary_keywords' => 8,
        'secondary_keyword' => 80,
        'meta_title' => 60,
        'meta_description' => 160,
        'links' => 5,
        'anchor' => 80,
        'url' => 1024,
        'input_characters' => (int) env('AI_MAX_INPUT_CHARACTERS', 6000),
        'min_words' => 250,
        'max_words' => (int) env('AI_MAX_ARTICLE_WORDS', 1500),
        'default_words' => 800,
    ],
];
