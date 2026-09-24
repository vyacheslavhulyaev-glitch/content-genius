<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Exceptions\UnserializableResponse;

class AIController extends Controller
{
    public function __invoke(ClientContract $client): Response|JsonResponse
    {
        try {
            $response = $client->chat()->create([
                'model' => config('services.openai.model'),
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => 'Hello from Laravel',
                    ],
                ],
            ]);
        } catch (ErrorException|RateLimitException|ServerException|TransporterException|UnserializableResponse) {
            return response()->json(['error' => 'AI service unavailable'], 503);
        }

        $content = $response->choices[0]->message->content ?? null;

        if ($content === null) {
            return response()->json(['error' => 'AI service unavailable'], 503);
        }

        return response($content, 200)->header('Content-Type', 'text/plain');
    }
}
