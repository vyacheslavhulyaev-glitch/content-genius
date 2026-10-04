<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class AiBudgetExceeded extends RuntimeException
{
    public function __construct(private string $period = 'daily')
    {
        parent::__construct('AI budget reached');
    }

    public function render(): JsonResponse
    {
        $reset = $this->period === 'monthly'
            ? now('UTC')->startOfMonth()->addMonth()
            : now('UTC')->addDay()->startOfDay();
        $retry = max(1, $reset->timestamp - now('UTC')->timestamp);

        return response()->json([
            'error' => ucfirst($this->period).' AI budget reached', 'code' => 'ai_global_budget_exceeded',
            'retry_after' => $retry, 'reset_at' => $reset->toIso8601ZuluString(),
        ], 429)->withHeaders(['Retry-After' => (string) $retry]);
    }
}
