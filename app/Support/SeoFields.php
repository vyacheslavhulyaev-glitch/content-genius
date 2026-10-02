<?php

namespace App\Support;

use Closure;

final class SeoFields
{
    public const INPUTS = ['primary_keyword', 'secondary_keywords', 'meta_title', 'meta_description', 'links'];

    public static function normalize(array $fields): array
    {
        foreach (['primary_keyword', 'meta_title', 'meta_description'] as $field) {
            if (isset($fields[$field]) && is_string($fields[$field])) {
                $fields[$field] = trim($fields[$field]);
            }
        }
        if (isset($fields['secondary_keywords']) && is_array($fields['secondary_keywords'])) {
            $fields['secondary_keywords'] = array_map(fn ($keyword) => is_string($keyword) ? trim($keyword) : $keyword, $fields['secondary_keywords']);
        }
        if (isset($fields['links']) && is_array($fields['links'])) {
            $fields['links'] = array_map(function ($link) {
                if (is_array($link)) {
                    foreach (['anchor', 'url'] as $field) {
                        if (isset($link[$field]) && is_string($link[$field])) {
                            $link[$field] = trim($link[$field]);
                        }
                    }
                }

                return $link;
            }, $fields['links']);
        }

        return $fields;
    }

    public static function rules(?string $primaryKeyword = null): array
    {
        return [
            'primary_keyword' => ['nullable', 'string', 'max:'.config('generation.limits.primary_keyword')],
            'secondary_keywords' => ['nullable', 'array', 'list', 'max:'.config('generation.limits.secondary_keywords')],
            'secondary_keywords.*' => ['required', 'string', 'max:'.config('generation.limits.secondary_keyword'), 'distinct:ignore_case',
                function (string $attribute, mixed $value, Closure $fail) use ($primaryKeyword): void {
                    if (is_string($value) && $primaryKeyword !== null && mb_strtolower($value) === mb_strtolower(trim($primaryKeyword))) {
                        $fail('Secondary keywords must differ from the primary keyword.');
                    }
                },
            ],
            'meta_title' => ['nullable', 'string', 'max:'.config('generation.limits.meta_title')],
            'meta_description' => ['nullable', 'string', 'max:'.config('generation.limits.meta_description')],
            'links' => ['nullable', 'array', 'list', 'max:'.config('generation.limits.links')],
            'links.*' => ['required', 'array:anchor,url'],
            'links.*.anchor' => ['required', 'string', 'max:'.config('generation.limits.anchor')],
            'links.*.url' => ['required', 'string', 'max:'.config('generation.limits.url'), 'url:http,https', 'distinct'],
        ];
    }
}
