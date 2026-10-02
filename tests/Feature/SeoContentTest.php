<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SeoContentTest extends TestCase
{
    use RefreshDatabase;

    private function fields(): array
    {
        return [
            'title' => 'Casino guide', 'topic' => 'Responsible gambling', 'tone' => 'Professional', 'length' => '800 words',
            'primary_keyword' => 'online casino', 'secondary_keywords' => ['slots', 'sports betting'],
            'meta_title' => 'Online casino guide', 'meta_description' => 'Explore casino games and responsible play.',
            'links' => [['anchor' => 'game guide', 'url' => 'https://example.com/guide']],
        ];
    }

    public function test_seo_inputs_are_normalized_persisted_and_outputs_cannot_be_injected(): void
    {
        $fields = $this->fields();
        $fields['primary_keyword'] = ' online casino ';
        $fields['links'][0]['anchor'] = ' game guide ';
        $response = $this->actingAs(User::factory()->create(), 'web')->postJson('/api/contents', $fields + [
            'generated_title' => 'Injected', 'generated_meta_title' => 'Injected', 'generated_meta_description' => 'Injected',
        ])->assertCreated()->assertJsonPath('primary_keyword', 'online casino')
            ->assertJsonPath('links.0.anchor', 'game guide')
            ->assertJsonPath('generated_title', null)->assertJsonPath('generated_meta_title', null)
            ->assertJsonPath('generated_meta_description', null);
        $this->assertSame($this->fields()['secondary_keywords'], Content::findOrFail($response->json('id'))->secondary_keywords);
        $this->getJson('/api/contents')->assertOk()->assertJsonPath('0.meta_title', $fields['meta_title']);
    }

    public static function invalidSeo(): array
    {
        return [
            'primary type' => [['primary_keyword' => []], 'primary_keyword'],
            'primary length' => [['primary_keyword' => str_repeat('x', 121)], 'primary_keyword'],
            'secondary type' => [['secondary_keywords' => 'slots'], 'secondary_keywords'],
            'secondary list' => [['secondary_keywords' => ['name' => 'slots']], 'secondary_keywords'],
            'secondary count' => [['secondary_keywords' => range(1, 11)], 'secondary_keywords'],
            'secondary empty' => [['secondary_keywords' => [' ']], 'secondary_keywords.0'],
            'secondary length' => [['secondary_keywords' => [str_repeat('x', 121)]], 'secondary_keywords.0'],
            'secondary duplicates' => [['secondary_keywords' => [' Slots ', 'slots']], 'secondary_keywords.0'],
            'primary duplicate' => [['secondary_keywords' => ['Online Casino']], 'secondary_keywords.0'],
            'meta title' => [['meta_title' => str_repeat('x', 61)], 'meta_title'],
            'meta description' => [['meta_description' => str_repeat('x', 161)], 'meta_description'],
            'links type' => [['links' => 'url'], 'links'],
            'links count' => [['links' => array_fill(0, 11, ['anchor' => 'Guide', 'url' => 'https://example.com'])], 'links'],
            'links list' => [['links' => ['name' => ['anchor' => 'Guide', 'url' => 'https://example.com']]], 'links'],
            'empty anchor' => [['links' => [['anchor' => ' ', 'url' => 'https://example.com']]], 'links.0.anchor'],
            'long anchor' => [['links' => [['anchor' => str_repeat('x', 121), 'url' => 'https://example.com']]], 'links.0.anchor'],
            'javascript' => [['links' => [['anchor' => 'Guide', 'url' => 'javascript:alert(1)']]], 'links.0.url'],
            'ftp' => [['links' => [['anchor' => 'Guide', 'url' => 'ftp://example.com']]], 'links.0.url'],
            'relative URL' => [['links' => [['anchor' => 'Guide', 'url' => '/guide']]], 'links.0.url'],
            'URL duplicate' => [['links' => [
                ['anchor' => 'Guide', 'url' => 'https://example.com'],
                ['anchor' => 'Other', 'url' => ' https://example.com '],
            ]], 'links.0.url'],
            'extra link keys' => [['links' => [['anchor' => 'Guide', 'url' => 'https://example.com', 'html' => 'ignored']]], 'links.0'],
        ];
    }

    #[DataProvider('invalidSeo')]
    public function test_invalid_seo_is_rejected_on_creation_and_edit(array $invalid, string $field): void
    {
        $content = Content::factory()->create($this->fields());
        $this->actingAs($content->user, 'web');
        $before = $content->refresh()->getAttributes();
        $this->postJson('/api/contents', array_replace($this->fields(), $invalid))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson("/api/contents/{$content->id}", $invalid)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertDatabaseCount('contents', 1);
    }

    public function test_partial_primary_edit_checks_saved_secondary_keywords(): void
    {
        $content = Content::factory()->create($this->fields());
        $this->actingAs($content->user, 'web')->patchJson("/api/contents/{$content->id}", ['primary_keyword' => 'slots'])
            ->assertUnprocessable()->assertJsonValidationErrors('secondary_keywords.0');
    }

    public function test_language_versions_copy_inputs_without_output_and_have_independent_seo_staleness(): void
    {
        $content = Content::factory()->create($this->fields() + ['generated_content' => 'Saved article']);
        $content->forceFill([
            'generated_title' => 'Saved title', 'generated_meta_title' => 'Saved meta',
            'generated_meta_description' => 'Saved description', 'generation_fingerprint' => $content->generationInputs()->fingerprint(),
        ])->save();
        $this->actingAs($content->user, 'web');
        foreach (['uk', 'de'] as $language) {
            $id = $this->postJson("/api/contents/{$content->id}/translations", ['content_language' => $language])
                ->assertCreated()->assertJsonPath('primary_keyword', 'online casino')
                ->assertJsonPath('links', $content->links)->assertJsonPath('generated_content', null)
                ->assertJsonPath('generated_meta_title', null)->assertJsonPath('generated_title', null)->json('id');
            $this->patchJson("/api/contents/{$id}", ['meta_title' => 'Localized meta'])->assertOk();
            $this->assertSame('Online casino guide', $content->refresh()->meta_title);
            $this->assertFalse($content->is_generation_stale);
        }
        foreach (['primary_keyword' => 'casino online', 'secondary_keywords' => ['roulette'],
            'meta_title' => 'New meta', 'meta_description' => 'New description', 'links' => []] as $field => $value) {
            $original = $content->$field;
            $this->patchJson("/api/contents/{$content->id}", [$field => $value])->assertOk()->assertJsonPath('is_generation_stale', true);
            $this->patchJson("/api/contents/{$content->id}", [$field => $original])->assertOk()->assertJsonPath('is_generation_stale', false);
        }
    }

    public function test_migration_preserves_existing_output_and_fingerprint_and_is_reversible(): void
    {
        $content = Content::factory()->create(['generated_content' => 'Legacy output']);
        $fingerprint = $content->generationInputs()->fingerprint();
        $content->forceFill(['generation_fingerprint' => $fingerprint])->save();
        $migration = require database_path('migrations/2026_10_02_000000_add_seo_fields_to_contents_table.php');
        $migration->down();
        $migration->up();
        $content->refresh();
        $this->assertSame('Legacy output', $content->generated_content);
        $this->assertSame($fingerprint, $content->generationInputs()->fingerprint());
        $this->assertFalse($content->is_generation_stale);
        $this->assertNull($content->generated_meta_title);
    }
}
