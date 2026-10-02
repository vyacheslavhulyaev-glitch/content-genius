<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class GenerationRateLimited extends RuntimeException
{
    public function __construct(public int $resetTimestamp)
    {
        parent::__construct('AI request quota exhausted');
    }

    public function render(): JsonResponse
    {
        $retryAfter = max(1, $this->resetTimestamp - now()->timestamp);

        return response()->json([
            'error' => 'AI request limit reached', 'code' => 'generation_rate_limited',
            'retry_after' => $retryAfter, 'reset_at' => gmdate('Y-m-d\TH:i:s\Z', $this->resetTimestamp),
        ], 429)->withHeaders([
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) config('generation.quota.requests'),
            'X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => (string) $this->resetTimestamp,
        ]);
    }
}
