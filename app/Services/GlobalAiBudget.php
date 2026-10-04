<?php

namespace App\Services;

use App\Exceptions\AiAccountingUnavailable;
use App\Exceptions\AiBudgetExceeded;
use App\Models\AIRequest;
use App\Models\ProviderCall;
use Illuminate\Support\Facades\DB;

class GlobalAiBudget
{
    public function __construct(private AiPricing $pricing) {}

    public function reserve(AIRequest $request, string $operation, array $parameters): ProviderCall
    {
        return DB::transaction(function () use ($request, $operation, $parameters): ProviderCall {
            // A write locks the singleton on MySQL/PostgreSQL and acquires SQLite's write lock.
            if (DB::table('ai_budget_locks')->where('id', 1)->increment('version') !== 1) {
                throw new AiAccountingUnavailable;
            }
            $now = now('UTC');
            $rates = $this->pricing->rates((string) $parameters['model']);
            $input = strlen(json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                + max(0, (int) config('ai_usage.budget.input_token_margin'));
            $output = (int) $parameters['max_completion_tokens'];
            $cost = $this->pricing->estimate($rates, $input, $output);
            $usage = ProviderCall::where('budget_date', $now->toDateString())
                ->selectRaw('COUNT(*) AS calls')
                ->selectRaw('COALESCE(SUM(COALESCE(total_tokens, reserved_tokens)), 0) AS tokens')
                ->selectRaw('COALESCE(SUM(COALESCE(estimated_cost, reserved_cost)), 0) AS cost')
                ->selectRaw('COUNT(CASE WHEN estimated_cost IS NULL AND reserved_cost IS NULL THEN 1 END) AS unknown_costs')
                ->first();
            $limits = config('ai_usage.budget');
            if ($this->enabled($limits['monthly_cost'])) {
                $monthly = ProviderCall::where('budget_date', '>=', $now->copy()->startOfMonth()->toDateString())
                    ->where('budget_date', '<', $now->copy()->startOfMonth()->addMonth()->toDateString())
                    ->selectRaw('COALESCE(SUM(COALESCE(estimated_cost, reserved_cost)), 0) AS cost')
                    ->selectRaw('COUNT(CASE WHEN estimated_cost IS NULL AND reserved_cost IS NULL THEN 1 END) AS unknown_costs')
                    ->first();
                if ($cost === null || $monthly->unknown_costs > 0) {
                    throw new AiAccountingUnavailable;
                }
                if (round((float) $monthly->cost + (float) $cost, 8) > (float) $limits['monthly_cost']) {
                    throw new AiBudgetExceeded('monthly');
                }
            }
            if ($this->enabled($limits['daily_cost']) && ($cost === null || $usage->unknown_costs > 0)) {
                throw new AiAccountingUnavailable;
            }
            foreach (['daily_calls' => [$usage->calls, 1], 'daily_tokens' => [$usage->tokens, $input + $output],
                'daily_cost' => [$usage->cost, (float) $cost]] as $key => [$used, $reserved]) {
                if ($this->enabled($limits[$key]) && round((float) $used + $reserved, 8) > (float) $limits[$key]) {
                    throw new AiBudgetExceeded;
                }
            }

            return ProviderCall::create([
                'ai_request_id' => $request->id, 'content_id' => $request->fresh()?->content_id,
                'provider' => 'openai', 'model' => (string) $parameters['model'], 'operation' => $operation,
                'status' => 'pending', 'currency' => config('ai_usage.currency'),
                'input_price_per_million' => $rates['input'] ?? null, 'output_price_per_million' => $rates['output'] ?? null,
                'reserved_tokens' => $input + $output, 'reserved_cost' => $cost,
                'budget_date' => $now->toDateString(), 'started_at' => $now,
            ]);
        });
    }

    private function enabled(mixed $limit): bool
    {
        if ($limit === null || $limit === '') {
            return false;
        }
        if (! is_numeric($limit) || ! is_finite((float) $limit) || $limit < 0) {
            throw new AiAccountingUnavailable;
        }

        return true;
    }
}
