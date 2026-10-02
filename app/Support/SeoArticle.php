<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use UnexpectedValueException;

final readonly class SeoArticle
{
    public function __construct(public string $title, public string $markdown, public string $metaTitle, public string $metaDescription) {}

    public static function responseFormat(): array
    {
        $fields = ['article_title', 'article_markdown', 'meta_title', 'meta_description'];

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'seo_article',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => array_fill_keys($fields, ['type' => 'string']),
                    'required' => $fields,
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    public static function fromJson(string $json, GenerationInputs $inputs): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data) || count($data) !== 4) {
            throw new UnexpectedValueException('Invalid SEO article');
        }
        $data = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data);
        if (Validator::make($data, [
            'article_title' => ['required', 'string', 'max:255'],
            'article_markdown' => ['required', 'string', 'max:100000'],
            'meta_title' => ['required', 'string', 'max:60'],
            'meta_description' => ['required', 'string', 'max:160'],
        ])->fails()) {
            throw new UnexpectedValueException('Invalid SEO article');
        }

        $markdown = str_replace(["\r\n", "\r"], "\n", $data['article_markdown']);
        if (! str_starts_with($markdown, '# '.$data['article_title']."\n")
            || preg_match_all('/^#[ \t]+\S/m', $markdown) !== 1
            || ! preg_match('/^##[ \t]+\S/m', $markdown)
            || ! preg_match('/^(?!#|\s)\S.+/m', $markdown)) {
            throw new UnexpectedValueException('Invalid article structure');
        }
        foreach ($inputs->seo['links'] as $link) {
            $anchor = preg_replace('/([\\\\`*_{}\[\]()#+\-.!>|])/', '\\\\$1', $link['anchor']);
            $present = false;
            foreach ([$anchor, $link['anchor']] as $label) {
                $present = $present || str_contains($markdown, '['.$label.'](<'.$link['url'].'>)')
                    || str_contains($markdown, '['.$label.']('.$link['url'].')');
            }
            if (! $present) {
                throw new UnexpectedValueException('Article is missing a supplied link');
            }
        }

        return new self($data['article_title'], $markdown, $data['meta_title'], $data['meta_description']);
    }

    public function moderationText(): string
    {
        return json_encode([
            'article_title' => $this->title, 'article_markdown' => $this->markdown,
            'meta_title' => $this->metaTitle, 'meta_description' => $this->metaDescription,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
