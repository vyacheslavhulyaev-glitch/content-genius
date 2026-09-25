<?php

namespace App\Http\Controllers;

use App\Models\AIRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Exceptions\UnserializableResponse;
use Throwable;

class GenerateContentController extends Controller
{
    public function __invoke(Request $request, string $content, ClientContract $client): JsonResponse
    {
        $content = $request->user()->contents()->findOrFail($content);

        if ($content->generated_content !== null || $content->aiRequests()->where('status', 'pending')->exists()) {
            return response()->json(['error' => 'Content is already generated or generation is pending'], 409);
        }

        try {
            $aiRequest = DB::transaction(fn () => $request->user()->aiRequests()->create([
                'content_id' => $content->id,
                'status' => 'pending',
            ]));
        } catch (QueryException) {
            Log::error('Unable to create generation request', ['content_id' => $content->id]);

            return response()->json(['error' => 'Unable to save generated content'], 500);
        }

        try {
            $response = $client->chat()->create([
                'model' => config('services.openai.model'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Write content using the supplied draft details. Return only the generated text.'],
                    ['role' => 'user', 'content' => "Title: {$content->title}\nTopic: {$content->topic}\nTone: {$content->tone}\nLength: {$content->length}"],
                ],
            ]);
        } catch (ErrorException|RateLimitException|ServerException|TransporterException|UnserializableResponse) {
            $this->markFailed($aiRequest, 'Provider request failed');

            return response()->json(['error' => 'AI service unavailable'], 503);
        }

        $text = trim($response->choices[0]->message->content ?? '');
        $tokensUsed = $response->usage?->totalTokens;

        if ($text === '') {
            $this->markFailed($aiRequest, 'Provider returned unusable content', $tokensUsed);

            return response()->json(['error' => 'AI service unavailable'], 503);
        }

        try {
            DB::transaction(function () use ($content, $aiRequest, $text, $tokensUsed): void {
                $content->update(['generated_content' => $text]);
                $aiRequest->update([
                    'status' => 'completed',
                    'tokens_used' => $tokensUsed,
                    'cost' => null,
                    'error_message' => null,
                ]);
            });
        } catch (QueryException) {
            $this->markFailed($aiRequest, 'Failed to persist generation', $tokensUsed);
            Log::error('Unable to persist generation', ['ai_request_id' => $aiRequest->id]);

            return response()->json(['error' => 'Unable to save generated content'], 500);
        }

        return response()->json([
            'content' => $content,
            'ai_request' => $aiRequest->only(['id', 'status', 'tokens_used', 'cost']),
        ]);
    }

    private function markFailed(AIRequest $aiRequest, string $message, ?int $tokensUsed = null): void
    {
        try {
            AIRequest::whereKey($aiRequest->id)->where('status', 'pending')->update([
                'status' => 'failed',
                'tokens_used' => $tokensUsed,
                'cost' => null,
                'error_message' => $message,
            ]);
        } catch (Throwable) {
            Log::error('Unable to mark generation request failed', ['ai_request_id' => $aiRequest->id]);
        }
    }
}
