<?php

namespace App\Http\Controllers;

use App\Actions\GenerateContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenAI\Contracts\ClientContract;

class RegenerateContentController extends Controller
{
    public function __invoke(Request $request, string $content, ClientContract $client, GenerateContent $generate): JsonResponse
    {
        return $generate($request->user(), $content, $client, regenerate: true);
    }
}
