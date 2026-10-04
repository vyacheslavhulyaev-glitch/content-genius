<?php

return [
    'currency' => 'USD',
    // Standard text pricing per one million tokens, verified 2026-10-02.
    // Cached input is charged at the standard input rate for a conservative estimate.
    'pricing' => [
        'gpt-5.6-terra' => ['input' => 2.00, 'output' => 12.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
    ],
    'aliases' => ['gpt-4o-mini-2024-07-18' => 'gpt-4o-mini'],
    'budget' => [
        // Empty values disable individual ceilings; zero blocks all calls.
        'daily_cost' => env('AI_DAILY_COST_LIMIT_USD', 0.10),
        'monthly_cost' => env('AI_MONTHLY_COST_LIMIT_USD', 1.00),
        'daily_calls' => env('AI_DAILY_PROVIDER_CALL_LIMIT', 50),
        'daily_tokens' => env('AI_DAILY_TOKEN_LIMIT', 75000),
        'input_token_margin' => (int) env('AI_INPUT_TOKEN_RESERVATION_MARGIN', 1024),
    ],
    'moderation_max_output_tokens' => (int) env('AI_MODERATION_MAX_OUTPUT_TOKENS', 256),
];
