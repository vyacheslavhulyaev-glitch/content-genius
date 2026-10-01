<?php

namespace Tests\Feature;

use App\Actions\ManageContent;
use App\Enums\ContentLanguage;
use App\Models\Content;
use App\Models\ContentGroup;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentGroupTest extends TestCase
{
    use RefreshDatabase;

    public static function languages(): array
    {
        return [['en'], ['uk'], ['de']];
    }

    public static function generationStates(): array
    {
        return [
            'null draft' => [null, false, false],
            'empty draft' => ['', false, false],
            'whitespace draft' => ['   ', false, false],
            'generated current' => ['Generated text', false, true],
            'generated stale' => ['Generated text', true, true],
            'empty output with changed inputs' => ['', true, false],
        ];
    }

    #[DataProvider('generationStates')]
    public function test_translation_summaries_expose_derived_generation_state(?string $text, bool $edited, bool $hasGenerated): void
    {
        $source = Content::factory()->create(['generated_content' => $text]);
        $source->forceFill(['generation_fingerprint' => $source->generationInputs()->fingerprint()])->save();
        if ($edited) {
            $source->update(['topic' => 'Edited topic']);
        }
        $this->actingAs($source->user, 'web');
        $translationId = $this->postJson("/api/contents/{$source->id}/translations", ['content_language' => 'de'])
            ->assertCreated()->assertJsonPath('translations.0.has_generated_content', $hasGenerated)
            ->assertJsonPath('translations.0.is_generation_stale', $edited)
            ->assertJsonPath('translations.1.has_generated_content', false)
            ->assertJsonPath('translations.1.is_generation_stale', false)->json('id');
        $response = $this->getJson('/api/contents')->assertOk();
        foreach ($response->json() as $version) {
            $this->assertSame([
                ['id' => $source->id, 'content_language' => 'en', 'has_generated_content' => $hasGenerated, 'is_generation_stale' => $edited],
                ['id' => $translationId, 'content_language' => 'de', 'has_generated_content' => false, 'is_generation_stale' => false],
            ], $version['translations']);
        }
    }

    #[DataProvider('languages')]
    public function test_new_article_creates_an_owned_group_with_its_first_language(string $language): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user, 'web')->postJson('/api/contents', [
            'title' => 'Title', 'topic' => 'Topic', 'content_language' => $language,
            'content_group_id' => 999, 'primary_language' => 'fr',
        ])->assertCreated()->assertJsonPath('primary_language', $language)
            ->assertJsonPath('translations', [['id' => 1, 'content_language' => $language, 'has_generated_content' => false, 'is_generation_stale' => false]]);

        $content = Content::sole();
        $group = ContentGroup::sole();
        $this->assertSame($group->id, $response->json('content_group_id'));
        $this->assertTrue($content->contentGroup->is($group));
        $this->assertTrue($group->user->is($user));
        $this->assertTrue($user->contentGroups()->sole()->is($group));
        $this->assertTrue($group->contents()->sole()->is($content));
        $this->assertSame(ContentLanguage::from($language), $group->primary_language);
    }

    #[DataProvider('languages')]
    public function test_adds_a_fresh_language_version_without_copying_generated_state(string $language): void
    {
        $user = User::factory()->create();
        $sourceLanguage = $language === 'en' ? 'uk' : 'en';
        $source = Content::factory()->for($user)->create([
            'content_language' => $sourceLanguage, 'title' => 'Original title', 'topic' => 'Original topic',
            'tone' => 'Friendly', 'length' => 'Short', 'generated_content' => 'Original output',
            'metadata' => ['private' => 'Do not copy'],
        ])->refresh();
        $source->forceFill(['generation_fingerprint' => $source->generationInputs()->fingerprint()])->save();
        $before = $source->getAttributes();
        $client = new ClientFake([]);
        $this->app->instance(ClientContract::class, $client);

        $response = $this->actingAs($user, 'web')->postJson("/api/contents/{$source->id}/translations", [
            'content_language' => $language, 'generated_content' => 'Injected output', 'title' => 'Injected title',
            'content_group_id' => 999, 'user_id' => 999, 'primary_language' => $language,
        ])->assertCreated()->assertJsonPath('content_group_id', $source->content_group_id)
            ->assertJsonPath('user_id', $user->id)->assertJsonPath('content_language', $language)
            ->assertJsonPath('primary_language', $sourceLanguage)
            ->assertJsonPath('title', 'Original title')->assertJsonPath('topic', 'Original topic')
            ->assertJsonPath('tone', 'Friendly')->assertJsonPath('length', 'Short')
            ->assertJsonPath('generated_content', null)->assertJsonPath('metadata', null)
            ->assertJsonPath('is_generation_stale', false)->assertJsonMissingPath('generation_fingerprint');

        $this->assertNull(Content::findOrFail($response->json('id'))->generation_fingerprint);
        $this->assertNotSame($source->id, $response->json('id'));
        $this->assertSame($before, $source->refresh()->getAttributes());
        $this->assertDatabaseCount('content_groups', 1);
        $this->assertDatabaseCount('contents', 2);
        $this->assertDatabaseCount('ai_requests', 0);
        $client->assertNothingSent();
        $this->assertSame([
            ['id' => $source->id, 'content_language' => $sourceLanguage, 'has_generated_content' => true, 'is_generation_stale' => false],
            ['id' => $response->json('id'), 'content_language' => $language, 'has_generated_content' => false, 'is_generation_stale' => false],
        ], $response->json('translations'));
    }

    public static function invalidLanguages(): array
    {
        return [[[]], [['content_language' => 'fr']], [['content_language' => 'ua']],
            [['content_language' => null]], [['content_language' => ['de']]]];
    }

    public function test_three_language_resources_keep_independent_states_through_reload_generation_and_edit(): void
    {
        $source = Content::factory()->create(['generated_content' => 'Original English text']);
        $source->forceFill(['generation_fingerprint' => $source->generationInputs()->fingerprint()])->save();
        $sourceBefore = $source->refresh()->getAttributes();
        $client = new ClientFake([
            CreateResponse::fake(['choices' => [['message' => ['content' => 'Generated German text']]]]),
        ]);
        $this->app->instance(ClientContract::class, $client);
        $this->actingAs($source->user, 'web');
        $expected = [
            ['id' => $source->id, 'content_language' => 'en', 'has_generated_content' => true, 'is_generation_stale' => false],
        ];
        foreach (['de', 'uk'] as $language) {
            $response = $this->postJson("/api/contents/{$source->id}/translations", ['content_language' => $language])
                ->assertCreated()->assertJsonPath('generated_content', null)->assertJsonPath('is_generation_stale', false);
            $translation = Content::findOrFail($response->json('id'));
            $this->assertNull($translation->generated_content);
            $this->assertNull($translation->generation_fingerprint);
            $expected[] = ['id' => $translation->id, 'content_language' => $language, 'has_generated_content' => false, 'is_generation_stale' => false];
            $response->assertJsonPath('translations', $expected);
        }
        $this->assertSame($sourceBefore, $source->refresh()->getAttributes());
        $beforeReload = Content::orderBy('id')->get()->map->getAttributes()->all();
        for ($reload = 0; $reload < 3; $reload++) {
            $response = $this->getJson('/api/contents')->assertOk()->assertJsonCount(3);
            foreach ($response->json() as $version) {
                $this->assertSame($expected, $version['translations']);
                $this->assertSame($version['id'] === $source->id ? 'Original English text' : null, $version['generated_content']);
            }
        }
        $this->assertSame($beforeReload, Content::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('ai_requests', 0);
        $client->assertNothingSent();

        $germanId = $expected[1]['id'];
        $expected[1]['has_generated_content'] = true;
        $this->postJson("/api/contents/{$germanId}/generate")->assertOk()
            ->assertJsonPath('content.generated_content', 'Generated German text')
            ->assertJsonPath('content.translations', $expected);
        foreach ($this->getJson('/api/contents')->assertOk()->json() as $version) {
            $this->assertSame($expected, $version['translations']);
        }

        $expected[1]['is_generation_stale'] = true;
        $this->patchJson("/api/contents/{$germanId}", ['topic' => 'Changed German topic'])->assertOk()
            ->assertJsonPath('generated_content', 'Generated German text')->assertJsonPath('translations', $expected);
        foreach ($this->getJson('/api/contents')->assertOk()->json() as $version) {
            $this->assertSame($expected, $version['translations']);
        }
        $this->assertSame($sourceBefore, $source->refresh()->getAttributes());
        $this->assertNull(Content::findOrFail($expected[2]['id'])->generated_content);
        $this->assertNull(Content::findOrFail($expected[2]['id'])->generation_fingerprint);
        $this->assertDatabaseCount('ai_requests', 1);
        $client->chat()->assertSent(1);
    }

    #[DataProvider('invalidLanguages')]
    public function test_translation_requires_a_supported_language(array $fields): void
    {
        $source = Content::factory()->create();
        $this->actingAs($source->user, 'web')->postJson("/api/contents/{$source->id}/translations", $fields)
            ->assertUnprocessable()->assertJsonValidationErrors('content_language');
        $this->assertDatabaseCount('contents', 1);
        $this->assertDatabaseCount('content_groups', 1);
    }

    public function test_guest_foreign_and_missing_sources_are_rejected(): void
    {
        $source = Content::factory()->create();
        $this->postJson("/api/contents/{$source->id}/translations", ['content_language' => 'de'])->assertUnauthorized();
        $this->actingAs(User::factory()->admin()->create(), 'web');
        foreach ([$source->id, $source->id + 999] as $id) {
            $this->postJson("/api/contents/{$id}/translations", ['content_language' => 'de'])->assertNotFound();
        }
        $this->assertDatabaseCount('contents', 1);
        $this->assertDatabaseCount('content_groups', 1);
    }

    public function test_duplicate_add_and_language_edit_return_validation_errors_without_changes(): void
    {
        $source = Content::factory()->create();
        $this->actingAs($source->user, 'web');
        $translation = $this->postJson("/api/contents/{$source->id}/translations", ['content_language' => 'de'])->assertCreated();
        $this->postJson("/api/contents/{$source->id}/translations", ['content_language' => 'de'])
            ->assertUnprocessable()->assertJsonValidationErrors('content_language');
        $this->postJson('/api/contents/'.$translation->json('id').'/translations', ['content_language' => 'en'])
            ->assertUnprocessable()->assertJsonValidationErrors('content_language');
        $this->patchJson("/api/contents/{$source->id}", ['content_language' => 'de', 'title' => 'Unwanted title'])
            ->assertUnprocessable()->assertJsonValidationErrors('content_language');
        $this->assertSame('Title', $source->refresh()->title);
        $this->assertSame(ContentLanguage::English, $source->content_language);
        $this->assertSame(ContentLanguage::English, $source->contentGroup->primary_language);
        $this->assertDatabaseCount('contents', 2);
        $this->assertDatabaseCount('content_groups', 1);
    }

    public function test_editing_a_primary_language_updates_group_but_secondary_edits_do_not(): void
    {
        $source = Content::factory()->create();
        $this->actingAs($source->user, 'web');
        $translationId = $this->postJson("/api/contents/{$source->id}/translations", ['content_language' => 'de'])->json('id');
        $this->patchJson("/api/contents/{$translationId}", ['content_language' => 'uk'])
            ->assertOk()->assertJsonPath('primary_language', 'en');
        $this->patchJson("/api/contents/{$source->id}", ['content_language' => 'de'])
            ->assertOk()->assertJsonPath('primary_language', 'de')
            ->assertJsonPath('translations', [
                ['id' => $source->id, 'content_language' => 'de', 'has_generated_content' => false, 'is_generation_stale' => false],
                ['id' => $translationId, 'content_language' => 'uk', 'has_generated_content' => false, 'is_generation_stale' => false],
            ]);
        $this->assertSame(ContentLanguage::German, $source->contentGroup->primary_language);
    }

    public function test_deleting_primary_promotes_lowest_surviving_id_and_last_delete_cleans_group(): void
    {
        $source = Content::factory()->create();
        $this->actingAs($source->user, 'web');
        $germanId = $this->postJson("/api/contents/{$source->id}/translations", ['content_language' => 'de'])->json('id');
        $ukrainianId = $this->postJson("/api/contents/{$source->id}/translations", ['content_language' => 'uk'])->json('id');
        $request = $source->user->aiRequests()->create(['content_id' => $source->id, 'status' => 'completed']);

        $this->deleteJson("/api/contents/{$source->id}")->assertNoContent();
        $this->assertSame(ContentLanguage::German, ContentGroup::sole()->primary_language);
        $this->assertNull($request->refresh()->content_id);
        $this->assertDatabaseCount('contents', 2);
        $this->getJson('/api/contents')->assertOk()->assertJsonPath('0.primary_language', 'de')
            ->assertJsonPath('0.translations', [
                ['id' => $germanId, 'content_language' => 'de', 'has_generated_content' => false, 'is_generation_stale' => false],
                ['id' => $ukrainianId, 'content_language' => 'uk', 'has_generated_content' => false, 'is_generation_stale' => false],
            ]);

        $this->deleteJson("/api/contents/{$ukrainianId}")->assertNoContent();
        $this->assertSame(ContentLanguage::German, ContentGroup::sole()->primary_language);
        $this->assertDatabaseHas('contents', ['id' => $germanId]);
        $this->deleteJson("/api/contents/{$germanId}")->assertNoContent();
        $this->assertDatabaseCount('content_groups', 0);
        $this->assertDatabaseCount('contents', 0);
        $this->assertDatabaseCount('ai_requests', 1);
    }

    public function test_generation_and_regeneration_affect_only_the_requested_version(): void
    {
        $source = Content::factory()->create(['generated_content' => 'Original English text']);
        $before = $source->refresh()->getAttributes();
        $this->actingAs($source->user, 'web');
        $germanId = $this->postJson("/api/contents/{$source->id}/translations", ['content_language' => 'de'])->json('id');
        $client = new ClientFake([
            CreateResponse::fake(['choices' => [['message' => ['content' => 'Erster Text']]]]),
            CreateResponse::fake(['choices' => [['message' => ['content' => 'Zweiter Text']]]]),
        ]);
        $this->app->instance(ClientContract::class, $client);
        foreach (['generate' => 'Erster Text', 'regenerate' => 'Zweiter Text'] as $operation => $text) {
            $this->postJson("/api/contents/{$germanId}/{$operation}")->assertOk()
                ->assertJsonPath('content.id', $germanId)->assertJsonPath('content.content_language', 'de')
                ->assertJsonPath('content.generated_content', $text)->assertJsonPath('content.primary_language', 'en')
                ->assertJsonCount(2, 'content.translations');
            $this->assertSame($before, $source->refresh()->getAttributes());
        }
        $this->assertDatabaseCount('ai_requests', 2);
        $this->assertSame(2, DB::table('ai_requests')->where('content_id', $germanId)->count());
        $client->chat()->assertSent(2);
        $client->chat()->assertSent(fn (string $method, array $parameters): bool => str_contains($parameters['messages'][0]['content'], 'The generated content must be written in German.'));
    }

    public static function invalidDatabaseRows(): array
    {
        return [['duplicate'], ['missing group'], ['unknown group']];
    }

    #[DataProvider('invalidDatabaseRows')]
    public function test_database_constraints_reject_invalid_versions(string $case): void
    {
        $source = Content::factory()->create();
        $groupId = match ($case) {
            'duplicate' => $source->content_group_id,
            'missing group' => null,
            'unknown group' => $source->content_group_id + 999,
        };
        $this->expectException(QueryException::class);
        DB::table('contents')->insert([
            'user_id' => $source->user_id, 'content_group_id' => $groupId,
            'title' => 'Title', 'topic' => 'Topic', 'content_language' => 'en',
        ]);
    }

    public function test_list_eager_loads_group_data_without_n_plus_one_queries(): void
    {
        $user = User::factory()->create();
        $source = Content::factory()->for($user)->create();
        $this->actingAs($user, 'web');
        $translationId = $this->postJson("/api/contents/{$source->id}/translations", ['content_language' => 'de'])->json('id');
        Content::factory()->for($user)->count(10)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $response = $this->getJson('/api/contents')->assertOk()->assertJsonCount(12);
            $queries = collect(DB::getQueryLog())->filter(fn ($query): bool => preg_match('/from ["`](contents|content_groups)["`]/i', $query['query']) === 1);
            $this->assertCount(3, $queries);
        } finally {
            DB::disableQueryLog();
        }
        foreach ($response->json() as $content) {
            $languages = array_column($content['translations'], 'content_language');
            $this->assertSame($languages, array_values(array_unique($languages)));
            $this->assertArrayNotHasKey('content_group', $content);
            if ($content['content_group_id'] === $source->content_group_id) {
                $this->assertSame([
                    ['id' => $source->id, 'content_language' => 'en', 'has_generated_content' => false, 'is_generation_stale' => false],
                    ['id' => $translationId, 'content_language' => 'de', 'has_generated_content' => false, 'is_generation_stale' => false],
                ], $content['translations']);
            }
        }
    }

    public function test_failed_content_insert_rolls_back_the_new_group(): void
    {
        $user = User::factory()->create();
        DB::unprepared("CREATE TRIGGER reject_content BEFORE INSERT ON contents BEGIN SELECT RAISE(ABORT, 'Test failure'); END");
        try {
            try {
                app(ManageContent::class)->create($user, ['title' => 'Title', 'topic' => 'Topic']);
                $this->fail('Expected insertion to fail.');
            } catch (QueryException) {
                $this->assertDatabaseCount('contents', 0);
                $this->assertDatabaseCount('content_groups', 0);
            }
        } finally {
            DB::unprepared('DROP TRIGGER reject_content');
        }
    }

    public function test_failed_primary_promotion_rolls_back_deletion(): void
    {
        $source = Content::factory()->create();
        app(ManageContent::class)->translate($source->user, (string) $source->id, ContentLanguage::German);
        DB::unprepared("CREATE TRIGGER reject_promotion BEFORE UPDATE ON content_groups BEGIN SELECT RAISE(ABORT, 'Test failure'); END");
        try {
            try {
                app(ManageContent::class)->delete($source->user, (string) $source->id);
                $this->fail('Expected promotion to fail.');
            } catch (QueryException) {
                $this->assertDatabaseCount('contents', 2);
                $this->assertSame(ContentLanguage::English, ContentGroup::sole()->primary_language);
            }
        } finally {
            DB::unprepared('DROP TRIGGER reject_promotion');
        }
    }
}
