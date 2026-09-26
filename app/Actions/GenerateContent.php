<?php

namespace App\Actions;

use App\Models\AIRequest;
use App\Models\Content;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenAI\Contracts\ClientContract;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Exceptions\UnserializableResponse;
use Throwable;

class GenerateContent
{
    public function __invoke(User $user, string $contentId, ClientContract $client, bool $regenerate = false): JsonResponse
    {
        try {
            $reservation = DB::transaction(function () use ($user, $contentId, $regenerate): ?array {
                $content = $user->contents()->lockForUpdate()->findOrFail($contentId);
                if (($content->generated_content !== null) !== $regenerate
                    || $content->aiRequests()->where('status', 'pending')->exists()) {
                    return null;
                }

                $inputs = $content->generationInputs();
                $aiRequest = $user->aiRequests()->create([
                    'content_id' => $content->id,
                    'status' => 'pending',
                ]);

                return [$inputs, $aiRequest];
            });
        } catch (QueryException) {
            Log::error('Unable to create generation request', ['content_id' => $contentId]);

            return response()->json(['error' => 'Unable to save generated content'], 500);
        }

        if ($reservation === null) {
            return response()->json(['error' => $regenerate
                ? 'Content is not generated or generation is pending'
                : 'Content is already generated or generation is pending'], 409);
        }
        [$inputs, $aiRequest] = $reservation;

        try {
            $response = $client->chat()->create([
                'model' => config('services.openai.model'),
                'messages' => [
                    ['role' => 'system', 'content' => 'Write content using the supplied draft details. Return only the generated text.'],
                    ['role' => 'user', 'content' => $inputs->prompt()],
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
            $content = DB::transaction(function () use ($user, $contentId, $inputs, $aiRequest, $text, $tokensUsed): ?Content {
                $content = $user->contents()->lockForUpdate()->find($contentId);
                if ($content === null) {
                    return null;
                }
                $content->forceFill([
                    'generated_content' => $text,
                    'generation_fingerprint' => $inputs->fingerprint(),
                ])->save();
                $aiRequest->update([
                    'status' => 'completed',
                    'tokens_used' => $tokensUsed,
                    'cost' => null,
                    'error_message' => null,
                ]);

                return $content;
            });
        } catch (QueryException) {
            $this->markFailed($aiRequest, 'Failed to persist generation', $tokensUsed);
            Log::error('Unable to persist generation', ['ai_request_id' => $aiRequest->id]);

            return response()->json(['error' => 'Unable to save generated content'], 500);
        }

        if ($content === null) {
            $this->markFailed($aiRequest, 'Content deleted during generation', $tokensUsed);

            return response()->json(['error' => 'Content not found'], 404);
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
