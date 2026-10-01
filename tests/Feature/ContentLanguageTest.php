<?php

namespace Tests\Feature;

use App\Enums\ContentLanguage;
use App\Models\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentLanguageTest extends TestCase
{
    use RefreshDatabase;

    public static function languages(): array
    {
        return [['en', 'English'], ['uk', 'Ukrainian'], ['de', 'German']];
    }

    #[DataProvider('languages')]
    public function test_creation_persists_each_supported_language(string $language): void
    {
        $response = $this->actingAs(User::factory()->create(), 'web')->postJson('/api/contents', [
            'title' => 'Title', 'topic' => 'Topic', 'content_language' => $language,
        ])->assertCreated()->assertJsonPath('content_language', $language)
            ->assertJsonPath('is_generation_stale', false);

        $this->assertDatabaseHas('contents', ['id' => $response->json('id'), 'content_language' => $language]);
        $this->assertSame(ContentLanguage::from($language), Content::sole()->content_language);
    }

    #[DataProvider('languages')]
    public function test_language_can_be_updated_without_changing_other_inputs(string $language): void
    {
        $user = User::factory()->create();
        $content = Content::factory()->for($user)->create([
            'title' => 'Title', 'topic' => 'Topic', 'tone' => 'Friendly', 'length' => 'Short',
            'content_language' => $language === 'en' ? 'uk' : 'en',
        ]);

        $this->actingAs($user, 'web')->patchJson("/api/contents/{$content->id}", ['content_language' => $language])
            ->assertOk()->assertJsonPath('content_language', $language)
            ->assertJsonPath('title', 'Title')->assertJsonPath('topic', 'Topic')
            ->assertJsonPath('tone', 'Friendly')->assertJsonPath('length', 'Short')
            ->assertJsonPath('is_generation_stale', false);

        $this->patchJson("/api/contents/{$content->id}", ['title' => 'Edited'])
            ->assertOk()->assertJsonPath('content_language', $language);
        $this->assertSame(ContentLanguage::from($language), $content->refresh()->content_language);
    }

    public static function invalidLanguages(): array
    {
        return [['fr'], ['ua'], ['EN'], ['en-US'], [''], [null], [42], [['en']]];
    }

    #[DataProvider('invalidLanguages')]
    public function test_invalid_languages_are_rejected_on_create_and_update(mixed $language): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->postJson('/api/contents', [
            'title' => 'Title', 'topic' => 'Topic', 'content_language' => $language,
        ])->assertUnprocessable()->assertJsonValidationErrors('content_language');
        $this->assertDatabaseCount('contents', 0);

        $content = Content::factory()->for($user)->create(['title' => 'Title', 'topic' => 'Topic']);
        $this->patchJson("/api/contents/{$content->id}", ['title' => 'Unwanted edit', 'content_language' => $language])
            ->assertUnprocessable()->assertJsonValidationErrors('content_language');
        $this->assertSame(ContentLanguage::English, $content->refresh()->content_language);
        $this->assertSame('Title', $content->title);
    }

    public function test_model_and_database_default_to_english(): void
    {
        $this->assertSame(ContentLanguage::English, (new Content)->content_language);
        $user = User::factory()->create();
        $group = $user->contentGroups()->create(['primary_language' => ContentLanguage::English]);
        $id = DB::table('contents')->insertGetId([
            'user_id' => $user->id, 'content_group_id' => $group->id, 'title' => 'Title', 'topic' => 'Topic',
        ]);
        $this->assertSame(ContentLanguage::English, Content::findOrFail($id)->content_language);
    }

    #[DataProvider('languages')]
    public function test_generation_and_regeneration_use_the_stored_language(string $language, string $name): void
    {
        $user = User::factory()->create();
        $content = Content::factory()->for($user)->create([
            'title' => 'Title', 'topic' => 'Topic', 'tone' => 'Friendly', 'length' => 'Short',
            'content_language' => $language,
        ]);
        $client = new ClientFake([CreateResponse::fake(), CreateResponse::fake()]);
        $this->app->instance(ClientContract::class, $client);
        $this->actingAs($user, 'web');

        foreach (['generate', 'regenerate'] as $operation) {
            $this->postJson("/api/contents/{$content->id}/{$operation}", ['content_language' => 'fr'])
                ->assertOk()->assertJsonPath('content.content_language', $language)
                ->assertJsonPath('content.is_generation_stale', false);
        }

        $client->chat()->assertSent(2);
        $client->chat()->assertSent(function (string $method, array $parameters) use ($name): bool {
            $this->assertStringContainsString("The generated content must be written in {$name}.", $parameters['messages'][0]['content']);
            $this->assertSame("Title: Title\nTopic: Topic\nTone: Friendly\nLength: Short", $parameters['messages'][1]['content']);

            return true;
        });
    }

    public function test_language_changes_make_generation_stale_and_regeneration_clears_it(): void
    {
        $user = User::factory()->create();
        $content = Content::factory()->for($user)->create(['title' => 'Title', 'topic' => 'Topic', 'generated_content' => 'Original text']);
        $fingerprint = $content->generationInputs()->fingerprint();
        $content->forceFill(['generation_fingerprint' => $fingerprint])->save();
        $this->actingAs($user, 'web');

        foreach (['uk', 'de'] as $language) {
            $this->patchJson("/api/contents/{$content->id}", ['content_language' => $language])
                ->assertOk()->assertJsonPath('is_generation_stale', true)
                ->assertJsonPath('generated_content', 'Original text');
            $this->getJson('/api/contents')->assertOk()->assertJsonPath('0.is_generation_stale', true);
            $this->assertSame($fingerprint, $content->refresh()->generation_fingerprint);
        }
        $this->patchJson("/api/contents/{$content->id}", ['content_language' => 'en'])
            ->assertOk()->assertJsonPath('is_generation_stale', false);
        $this->patchJson("/api/contents/{$content->id}", ['content_language' => 'de'])->assertOk();

        $client = new ClientFake([CreateResponse::fake(['choices' => [['message' => ['content' => 'Neuer Text']]]])]);
        $this->app->instance(ClientContract::class, $client);
        $this->postJson("/api/contents/{$content->id}/regenerate")->assertOk()
            ->assertJsonPath('content.is_generation_stale', false)->assertJsonPath('content.generated_content', 'Neuer Text');
        $client->chat()->assertSent(fn (string $method, array $parameters): bool => str_contains($parameters['messages'][0]['content'], 'The generated content must be written in German.'));
        $this->assertNotSame($fingerprint, $content->refresh()->generation_fingerprint);
        $this->assertSame($content->generationInputs()->fingerprint(), $content->generation_fingerprint);

        $this->patchJson("/api/contents/{$content->id}", ['content_language' => 'uk'])
            ->assertOk()->assertJsonPath('is_generation_stale', true);
        $this->patchJson("/api/contents/{$content->id}", ['content_language' => 'de'])
            ->assertOk()->assertJsonPath('is_generation_stale', false);
    }

    public function test_migration_defaults_existing_rows_and_preserves_legacy_staleness(): void
    {
        $groupBackfill = require database_path('migrations/2026_09_30_000002_backfill_content_groups.php');
        $groupSchema = require database_path('migrations/2026_09_30_000001_create_content_groups_table.php');
        $groupBackfill->down();
        $groupSchema->down();
        $migration = require database_path('migrations/2026_09_30_000000_add_content_language_to_contents_table.php');
        $migration->down();
        $user = User::factory()->create();
        $fingerprint = hash('sha256', json_encode(['Title', 'Topic', '', ''], JSON_THROW_ON_ERROR));
        $ids = [];
        foreach (['current', 'stale', 'draft'] as $state) {
            $ids[$state] = DB::table('contents')->insertGetId([
                'user_id' => $user->id, 'title' => $state === 'stale' ? 'Edited title' : 'Title', 'topic' => 'Topic',
                'generated_content' => $state === 'draft' ? null : 'Original text',
                'generation_fingerprint' => $state === 'draft' ? null : $fingerprint,
            ]);
        }
        $migration->up();
        $groupSchema->up();
        $groupBackfill->up();

        foreach ($ids as $state => $id) {
            $content = Content::findOrFail($id);
            $this->assertSame(ContentLanguage::English, $content->content_language);
            $this->assertSame($state === 'stale', $content->is_generation_stale);
            $this->assertSame($state === 'draft' ? null : $fingerprint, $content->generation_fingerprint);
        }
        $content = Content::findOrFail($ids['stale']);
        $content->update(['title' => 'Title']);
        $this->assertFalse($content->refresh()->is_generation_stale);
    }
}
