<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class GenerationPurposeRejected extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'Only SEO article requests are supported', 'code' => 'generation_purpose_blocked',
        ], 422);
    }
}
