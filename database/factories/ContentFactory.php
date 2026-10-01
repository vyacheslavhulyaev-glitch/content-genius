<?php

namespace Database\Factories;

use App\Enums\ContentLanguage;
use App\Models\Content;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Content> */
class ContentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => 'Title',
            'topic' => 'Topic',
            'content_language' => ContentLanguage::English,
            'content_group_id' => fn (array $attributes): int => User::findOrFail($attributes['user_id'])
                ->contentGroups()->create(['primary_language' => $attributes['content_language']])->id,
        ];
    }
}
