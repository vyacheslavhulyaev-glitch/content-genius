<?php

namespace App\Services;

use App\Models\AIRequest;
use App\Models\ProviderCall;
use Illuminate\Database\Eloquent\Builder;

class AiUsageAnalytics
{
    public function snapshot(): array
    {
        $now = now('UTC');
        $periods = [];
        foreach (['today' => $now->copy()->startOfDay(), 'month' => $now->copy()->startOfMonth(), 'all_time' => null] as $name => $start) {
            $calls = ProviderCall::query();
            $requests = AIRequest::query();
            if ($start !== null) {
                $calls->where('budget_date', '>=', $start->toDateString())->where('budget_date', '<=', $now->toDateString());
                $requests->where('created_at', '>=', $start)->where('created_at', '<', $now->copy()->addDay()->startOfDay());
            }
            $logical = $requests->selectRaw('COUNT(*) AS total')
                ->selectRaw("COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed")
                ->selectRaw("COUNT(CASE WHEN status = 'failed' THEN 1 END) AS failed")
                ->selectRaw("COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending")->first();
            $periods[$name] = [...$this->totals($calls), 'logical_requests' => [
                'total' => (int) $logical->total, 'completed' => (int) $logical->completed,
                'failed' => (int) $logical->failed, 'pending' => (int) $logical->pending,
            ]];
        }
        $operations = [];
        foreach (['generation', 'input_moderation', 'output_moderation'] as $operation) {
            $operations[$operation] = $this->totals(ProviderCall::where('operation', $operation));
        }

        return ['currency' => config('ai_usage.currency'), 'timezone' => 'UTC', 'periods' => $periods, 'operations' => $operations];
    }

    private function totals(Builder $query): array
    {
        $row = $query->selectRaw('COUNT(*) AS calls')
            ->selectRaw("COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed")
            ->selectRaw("COUNT(CASE WHEN status = 'failed' THEN 1 END) AS failed")
            ->selectRaw("COUNT(CASE WHEN status = 'pending' THEN 1 END) AS pending")
            ->selectRaw('COALESCE(SUM(input_tokens), 0) AS input_tokens')
            ->selectRaw('COALESCE(SUM(output_tokens), 0) AS output_tokens')
            ->selectRaw('COALESCE(SUM(total_tokens), 0) AS total_tokens')
            ->selectRaw('SUM(estimated_cost) AS estimated_cost')
            ->selectRaw('COUNT(CASE WHEN estimated_cost IS NULL THEN 1 END) AS unknown_cost_calls')
            ->selectRaw('COUNT(CASE WHEN input_tokens IS NULL OR output_tokens IS NULL OR total_tokens IS NULL THEN 1 END) AS unknown_usage_calls')
            ->first();

        return [
            'provider_calls' => (int) $row->calls, 'completed_provider_calls' => (int) $row->completed,
            'failed_provider_calls' => (int) $row->failed, 'pending_provider_calls' => (int) $row->pending,
            'input_tokens' => (int) $row->input_tokens, 'output_tokens' => (int) $row->output_tokens, 'total_tokens' => (int) $row->total_tokens,
            'estimated_cost' => $row->estimated_cost === null && $row->calls > 0 ? null : number_format((float) $row->estimated_cost, 8, '.', ''),
            'unknown_cost_calls' => (int) $row->unknown_cost_calls, 'unknown_usage_calls' => (int) $row->unknown_usage_calls,
        ];
    }
}
