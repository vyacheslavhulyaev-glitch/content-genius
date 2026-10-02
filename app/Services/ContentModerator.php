<?php

namespace App\Services;

use App\Exceptions\ModerationUnavailable;
use OpenAI\Contracts\ClientContract;
use Throwable;

class ContentModerator
{
    public const CATEGORIES = ['profanity', 'explicit_sexual', 'hate_harassment', 'dangerous_illegal_instructions'];

    public function allows(string $text, ClientContract $client): bool
    {
        // Catch failures only at the external classification boundary; never retain provider details.
        try {
            $response = $client->chat()->create([
                'model' => config('moderation.model'),
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
        } catch (Throwable) {
            throw new ModerationUnavailable;
        }

        return ! in_array(true, $decision, true);
    }
}
