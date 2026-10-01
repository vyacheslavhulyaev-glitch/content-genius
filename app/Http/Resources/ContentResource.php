<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            ...$this->resource->attributesToArray(),
            'primary_language' => $this->contentGroup->primary_language->value,
            'translations' => $this->contentGroup->contents->map(fn ($content): array => [
                'id' => $content->id,
                'content_language' => $content->content_language->value,
                'has_generated_content' => trim($content->generated_content ?? '') !== '',
                'is_generation_stale' => $content->is_generation_stale,
            ])->all(),
        ];
    }
}
