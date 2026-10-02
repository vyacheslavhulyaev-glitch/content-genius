<?php

namespace App\Http\Controllers;

use App\Actions\GenerateContent;
use App\Http\Requests\GenerateContentRequest;
use Illuminate\Http\JsonResponse;
use OpenAI\Contracts\ClientContract;

class RegenerateContentController extends Controller
{
    public function __invoke(GenerateContentRequest $request, string $content, ClientContract $client, GenerateContent $generate): JsonResponse
    {
        return $generate($request->user(), $content, $client, regenerate: true);
    }
}
