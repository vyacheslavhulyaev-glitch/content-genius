<?php

namespace App\Services;

use App\Exceptions\AiAccountingUnavailable;
use App\Exceptions\AiBudgetExceeded;
use App\Exceptions\ModerationUnavailable;
use App\Models\AIRequest;
use OpenAI\Contracts\ClientContract;
use Throwable;

class ContentModerator
{
    public function __construct(private TrackedOpenAI $provider) {}

    public const CATEGORIES = ['profanity', 'explicit_sexual', 'hate_harassment', 'dangerous_illegal_instructions'];

    public function allows(string $text, ClientContract $client, AIRequest $request, string $operation): bool
    {
        // Catch failures only at the external classification boundary; never retain provider details.
        try {
            $response = $this->provider->chat($client, $request, $operation, [
                'model' => config('moderation.model'),
                'max_completion_tokens' => max(1, (int) config('ai_usage.moderation_max_output_tokens')),
                'messages' => [
                    ['role' => 'system', 'content' => config('moderation.policy')],
                    ['role' => 'user', 'content' => $text],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'content_moderation',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => array_fill_keys(self::CATEGORIES, ['type' => 'boolean']),
                            'required' => self::CATEGORIES,
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ]);
            if (count($response->choices) !== 1 || $response->choices[0]->finishReason !== 'stop') {
                throw new ModerationUnavailable;
            }
            $decision = json_decode($response->choices[0]->message->content ?? '', true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decision) || count($decision) !== count(self::CATEGORIES)) {
                throw new ModerationUnavailable;
            }
            foreach (self::CATEGORIES as $category) {
                if (! isset($decision[$category]) || ! is_bool($decision[$category])) {
                    throw new ModerationUnavailable;
                }
            }
        } catch (AiBudgetExceeded|AiAccountingUnavailable $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ModerationUnavailable;
        }

        return ! in_array(true, $decision, true);
    }
}
