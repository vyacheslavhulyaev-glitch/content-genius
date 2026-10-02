<?php

namespace App\Actions;

use App\Exceptions\ModerationUnavailable;
use App\Http\Resources\ContentResource;
use App\Models\AIRequest;
use App\Models\Content;
use App\Models\User;
use App\Services\ContentModerator;
use App\Support\SeoArticle;
use App\Support\SeoFields;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use JsonException;
use OpenAI\Contracts\ClientContract;
use Throwable;
use UnexpectedValueException;

class GenerateContent
{
    public function __construct(private ContentModerator $moderator) {}

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
                Validator::make([
                    'title' => $inputs->title, 'topic' => $inputs->topic,
                    'tone' => $inputs->tone, 'length' => $inputs->length,
                    ...$inputs->seo,
                ], [
                    'title' => ['required', 'string', 'max:255'],
                    'topic' => ['required', 'string', 'max:255'],
                    'tone' => ['nullable', 'string', 'max:255'],
                    'length' => ['nullable', 'string', 'max:255'],
                    ...SeoFields::rules($inputs->seo['primary_keyword'] ?: $inputs->title),
                ])->validate();
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

        if ($failure = $this->moderate($inputs->prompt(), $client, $aiRequest, 'input')) {
            return $failure;
        }

        try {
            $response = $client->chat()->create([
                'model' => config('services.openai.model'),
                'messages' => [
                    ['role' => 'system', 'content' => $inputs->systemInstruction()],
                    ['role' => 'user', 'content' => $inputs->prompt()],
                ],
                'response_format' => SeoArticle::responseFormat(),
            ]);
        } catch (Throwable) {
            $this->markFailed($aiRequest, 'Provider request failed');

            return response()->json(['error' => 'AI service unavailable'], 503);
        }

        $tokensUsed = $response->usage?->totalTokens;
        try {
            if (count($response->choices) !== 1 || $response->choices[0]->finishReason !== 'stop') {
                throw new UnexpectedValueException('Incomplete SEO response');
            }
            $article = SeoArticle::fromJson($response->choices[0]->message->content ?? '', $inputs);
        } catch (JsonException|UnexpectedValueException) {
            $this->markFailed($aiRequest, 'Provider returned unusable content', $tokensUsed);

            return response()->json(['error' => 'AI service unavailable'], 503);
        }

        if ($failure = $this->moderate($article->moderationText(), $client, $aiRequest, 'output', $tokensUsed)) {
            return $failure;
        }

        try {
            $content = DB::transaction(function () use ($user, $contentId, $inputs, $aiRequest, $article, $tokensUsed): ?Content {
                $content = $user->contents()->lockForUpdate()->find($contentId);
                if ($content === null) {
                    return null;
                }
                $content->forceFill([
                    'generated_content' => $article->markdown,
                    'generated_title' => $article->title,
                    'generated_meta_title' => $article->metaTitle,
                    'generated_meta_description' => $article->metaDescription,
                    'generation_fingerprint' => $inputs->fingerprint(),
                ])->save();
                $aiRequest->update([
                    'status' => 'completed',
                    'tokens_used' => $tokensUsed,
                    'cost' => null,
                    'error_message' => null,
                ]);

                return $content->load(Content::GROUP_RELATIONS);
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
            'content' => new ContentResource($content),
            'ai_request' => $aiRequest->only(['id', 'status', 'tokens_used', 'cost']),
        ]);
    }

    private function moderate(string $text, ClientContract $client, AIRequest $aiRequest, string $stage, ?int $tokensUsed = null): ?JsonResponse
    {
        try {
            if ($this->moderator->allows($text, $client)) {
                return null;
            }
        } catch (ModerationUnavailable) {
            $this->markFailed($aiRequest, 'Moderation service unavailable', $tokensUsed);

            return response()->json(['error' => 'Moderation service unavailable', 'code' => 'moderation_unavailable'], 503);
        }

        $this->markFailed($aiRequest, ucfirst($stage).' blocked by moderation', $tokensUsed);

        return response()->json([
            'error' => ucfirst($stage).' blocked by moderation',
            'code' => 'moderation_'.$stage.'_blocked',
        ], 422);
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
