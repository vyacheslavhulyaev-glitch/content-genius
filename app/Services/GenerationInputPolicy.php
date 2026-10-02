<?php

namespace App\Services;

use App\Support\ArticleLength;
use App\Support\GenerationInputs;
use App\Support\SeoFields;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class GenerationInputPolicy
{
    public function __construct(private GenerationPurposeGuard $purpose) {}

    public static function unsupportedRules(): array
    {
        return array_fill_keys(['prompt', 'custom_prompt', 'system_prompt', 'messages', 'brief', 'body'], ['prohibited']);
    }

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.config('generation.limits.title')],
            'topic' => ['required', 'string', 'max:'.config('generation.limits.topic')],
            'tone' => ['nullable', 'string', 'max:'.config('generation.limits.tone')],
            'length' => ['nullable', 'string', 'max:'.config('generation.limits.length'), function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && ! ArticleLength::allowed($value)) {
                    $fail('Choose an article length between '.config('generation.limits.min_words').' and '.config('generation.limits.max_words').' words.');
                }
            }],
            ...self::unsupportedRules(),
        ];
    }

    public function validate(GenerationInputs $inputs): void
    {
        Validator::make([
            'title' => $inputs->title, 'topic' => $inputs->topic, 'tone' => $inputs->tone,
            'length' => $inputs->length, ...$inputs->seo,
        ], [...self::rules(), ...SeoFields::rules($inputs->seo['primary_keyword'] ?: $inputs->title)])->validate();
        if (mb_strlen($inputs->prompt()) > config('generation.limits.input_characters')) {
            throw ValidationException::withMessages(['inputs' => ['The combined AI inputs are too long. Shorten the brief, keywords or links.']]);
        }
        $this->purpose->assertAllowed([
            'title' => $inputs->title, 'topic' => $inputs->topic, 'tone' => $inputs->tone,
            'length' => $inputs->length, ...$inputs->seo,
        ]);
    }
}
