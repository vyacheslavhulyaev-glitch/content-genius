<?php

namespace App\Services;

class AiPricing
{
    public function rates(string $model): ?array
    {
        $model = config('ai_usage.aliases', [])[$model] ?? $model;
        $rates = config('ai_usage.pricing', [])[$model] ?? null;
        if (! is_array($rates) || ! isset($rates['input'], $rates['output'])
            || ! is_numeric($rates['input']) || ! is_numeric($rates['output'])
            || ! is_finite((float) $rates['input']) || ! is_finite((float) $rates['output'])
            || $rates['input'] < 0 || $rates['output'] < 0) {
            return null;
        }

        return ['input' => (float) $rates['input'], 'output' => (float) $rates['output']];
    }

    public function estimate(?array $rates, ?int $input, ?int $output): ?string
    {
        if ($rates === null || $input === null || $output === null || $input < 0 || $output < 0) {
            return null;
        }

        return number_format(($input * $rates['input'] + $output * $rates['output']) / 1000000, 8, '.', '');
    }
}
