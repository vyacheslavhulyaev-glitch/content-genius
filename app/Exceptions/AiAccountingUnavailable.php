<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class AiAccountingUnavailable extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'AI service temporarily unavailable', 'code' => 'ai_accounting_unavailable',
        ], 503);
    }
}
