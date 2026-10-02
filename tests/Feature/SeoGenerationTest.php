<?php

namespace Tests\Feature;

use App\Actions\ManageContent;
use App\Enums\ContentLanguage;
use App\Models\AIRequest;
use App\Models\Content;
use App\Services\ContentModerator;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\ResponseContract;
use OpenAI\Contracts\ResponseStreamContract;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use OpenAI\Testing\Requests\TestRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\SeoArticleResponse;
use Tests\TestCase;

class SeoGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function decision(?string $blocked = null): CreateResponse
    {
        $fields = array_fill_keys(ContentModerator::CATEGORIES, false);
        if ($blocked) {
            $fields[$blocked] = true;
        }

        return CreateResponse::fake(['choices' => [['message' => ['content' => json_encode($fields)]]]]);
    }

    private function content(bool $generated = false, string $language = 'en'): Content
    {
        $content = Content::factory()->create([
            'title' => 'Casino guide', 'topic' => 'Responsible play', 'content_language' => $language,
            'primary_keyword' => 'online casino', 'secondary_keywords' => ['slots', 'betting'],
            'meta_title' => 'Requested meta title', 'meta_description' => 'Requested description',
            'links' => [['anchor' => 'game guide', 'url' => 'https://example.com/games']],
            'generated_content' => $generated ? 'Previously saved body' : null,
        ]);
        if ($generated) {
            $content->forceFill([
                'generated_title' => 'Saved H1', 'generated_meta_title' => 'Saved meta',
                'generated_meta_description' => 'Saved description',
                'generation_fingerprint' => $content->generationInputs()->fingerprint(),
            ])->save();
        }
        $this->actingAs($content->user, 'web');

        return $content->refresh();
    }

    private function article(): array
    {
        return [
            'article_title' => 'Online casino guide',
            'article_markdown' => "# Online casino guide\n\n## Choosing games\n\nExplore online casino slots and betting with this [game guide](<https://example.com/games>).\n\n### Responsible play\n\nSet a budget before playing.",
            'meta_title' => 'Online casino games and betting',
            'meta_description' => 'Discover slots and responsible betting with our online casino guide.',
        ];
    }

    private function client(array $responses): ClientFake
    {
        $client = new ClientFake($responses);
        $this->app->instance(ClientContract::class, $client);

        return $client;
    }

    private function response(array $article): CreateResponse
    {
        return CreateResponse::fake(['choices' => [['message' => ['content' => json_encode($article)]]]]);
    }

    public static function modes(): array
    {
        $modes = [];
        foreach (['en', 'uk', 'de'] as $language) {
            foreach ([false, true] as $generated) {
                $modes[$language.($generated ? ' regenerate' : ' generate')] = [$language, $generated];
            }
        }

        return $modes;
    }

    #[DataProvider('modes')]
    public function test_pipeline_generates_markdown_and_separate_meta_fields(string $language, bool $generated): void
    {
        $content = $this->content($generated, $language);
        $inputs = $content->generationInputs();
        $article = $this->article();
        $client = $this->client([$this->decision(), $this->response($article), $this->decision()]);
        $operation = $generated ? 'regenerate' : 'generate';
        $this->postJson("/api/contents/{$content->id}/{$operation}", ['primary_keyword' => 'Injected'])
            ->assertOk()->assertJsonPath('content.generated_content', $article['article_markdown'])
            ->assertJsonPath('content.generated_title', $article['article_title'])
            ->assertJsonPath('content.generated_meta_title', $article['meta_title'])
            ->assertJsonPath('content.generated_meta_description', $article['meta_description'])
            ->assertJsonPath('content.meta_title', 'Requested meta title')
            ->assertJsonPath('content.primary_keyword', 'online casino')->assertJsonPath('content.is_generation_stale', false);
        $client->chat()->assertSent(3);
        $index = 0;
        $client->chat()->assertSent(function ($method, $parameters) use (&$index, $inputs, $article): bool {
            if ($index === 1) {
                $this->assertSame('seo_article', $parameters['response_format']['json_schema']['name']);
                $this->assertTrue($parameters['response_format']['json_schema']['strict']);
                $this->assertStringContainsString($inputs->languageInstruction(), $parameters['messages'][0]['content']);
                $this->assertStringContainsString('H3 headings are optional', $parameters['messages'][0]['content']);
                $this->assertStringContainsString('at least one meaningful H2', $parameters['messages'][0]['content']);
                $this->assertStringContainsString('not create a meta-keywords tag', $parameters['messages'][0]['content']);
                $this->assertSame($inputs->prompt(), $parameters['messages'][1]['content']);
            } elseif ($index === 0) {
                $this->assertSame($inputs->prompt(), $parameters['messages'][1]['content']);
                foreach (['online casino', 'slots', 'betting', 'Requested meta title', 'https://example.com/games'] as $value) {
                    $this->assertStringContainsString($value, $parameters['messages'][1]['content']);
                }
            } else {
                $this->assertSame($article, json_decode($parameters['messages'][1]['content'], true));
            }
            $index++;

            return true;
        });
        $this->assertSame(3, $index);
        $this->assertSame($inputs->fingerprint(), $content->refresh()->generation_fingerprint);
        $this->assertSame('completed', AIRequest::sole()->status);
    }

    public static function invalidArticles(): array
    {
        return [
            'invalid JSON' => ['bad_json'], 'missing meta' => ['missing_meta'],
            'meta type' => ['meta_type'], 'long title' => ['long_meta_title'],
            'long description' => ['long_meta_description'], 'missing H1' => ['missing_h1'],
            'missing H2' => ['missing_h2'], 'empty H2' => ['empty_h2'],
            'missing link' => ['missing_link'], 'extra H1' => ['extra_h1'],
            'empty body' => ['empty_body'], 'truncated' => ['truncated'],
            'extra field' => ['extra_field'],
        ];
    }

    #[DataProvider('invalidArticles')]
    public function test_invalid_output_preserves_all_saved_generated_fields(string $case): void
    {
        config(['app.debug' => true]);
        $content = $this->content(true);
        $before = $content->getAttributes();
        $article = $this->article();
        match ($case) {
            'missing_meta' => $article = array_diff_key($article, ['meta_title' => true]),
            'meta_type' => $article['meta_title'] = [],
            'long_meta_title' => $article['meta_title'] = str_repeat('x', 61),
            'long_meta_description' => $article['meta_description'] = str_repeat('x', 161),
            'missing_h1' => $article['article_markdown'] = str_replace('# Online casino guide', 'Title', $article['article_markdown']),
            'missing_h2' => $article['article_markdown'] = str_replace('## Choosing', 'Choosing', $article['article_markdown']),
            'empty_h2' => $article['article_markdown'] = str_replace('## Choosing games', '##', $article['article_markdown']),
            'missing_link' => $article['article_markdown'] = SeoArticleResponse::markdown($article['article_title']),
            'extra_h1' => $article['article_markdown'] .= "\n# Extra title",
            'empty_body' => $article['article_markdown'] = "# Online casino guide\n\n## Section\n\n### Subsection",
            'extra_field' => $article['secret'] = 'Sensitive provider output',
            default => null,
        };
        $response = CreateResponse::fake(['choices' => [[
            'message' => ['content' => $case === 'bad_json' ? 'Sensitive malformed provider output' : json_encode($article)],
            'finish_reason' => $case === 'truncated' ? 'length' : 'stop',
        ]]]);
        $client = $this->client([$this->decision(), $response]);
        $this->postJson("/api/contents/{$content->id}/regenerate")
            ->assertStatus(503)->assertExactJson(['error' => 'AI service unavailable']);
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertSame('failed', AIRequest::sole()->status);
        $client->chat()->assertSent(2);
    }

    public static function failureStages(): array
    {
        return [['input_block'], ['output_block'], ['provider_failure'], ['moderation_failure']];
    }

    #[DataProvider('failureStages')]
    public function test_moderation_and_provider_failures_preserve_h1_body_and_meta(string $stage): void
    {
        $content = $this->content(true);
        $before = $content->getAttributes();
        $responses = match ($stage) {
            'input_block' => [$this->decision('profanity')],
            'output_block' => [$this->decision(), $this->response($this->article()), $this->decision('profanity')],
            'provider_failure' => [$this->decision(), new RuntimeException('Private provider failure')],
            'moderation_failure' => [$this->decision(), $this->response($this->article()), new RuntimeException('Private moderation failure')],
        };
        $client = $this->client($responses);
        $this->postJson("/api/contents/{$content->id}/regenerate")->assertStatus(str_ends_with($stage, 'block') ? 422 : 503);
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertSame('failed', AIRequest::sole()->status);
        $client->chat()->assertSent(count($responses));
    }

    public function test_invalid_stored_seo_does_not_call_any_provider(): void
    {
        $content = $this->content();
        $content->update(['links' => [['anchor' => 'Unsafe', 'url' => 'javascript:alert(1)']]]);
        $client = $this->client([]);
        $this->postJson("/api/contents/{$content->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('links.0.url');
        $client->assertNothingSent();
        $this->assertDatabaseCount('ai_requests', 0);
    }

    public function test_regeneration_replaces_outputs_and_clears_seo_staleness(): void
    {
        $content = $this->content(true);
        $this->patchJson("/api/contents/{$content->id}", ['meta_title' => 'Changed guidance'])
            ->assertOk()->assertJsonPath('is_generation_stale', true)->assertJsonPath('generated_meta_title', 'Saved meta');
        $this->client([$this->decision(), $this->response($this->article()), $this->decision()]);
        $this->postJson("/api/contents/{$content->id}/regenerate")->assertOk()->assertJsonPath('content.is_generation_stale', false)
            ->assertJsonPath('content.generated_meta_title', $this->article()['meta_title']);
    }

    #[DataProvider('modes')]
    public function test_one_h2_article_without_h3_is_accepted_and_moderated(string $language, bool $generated): void
    {
        $content = $this->content($generated, $language);
        $article = $this->article();
        $article['article_markdown'] = str_replace('### Responsible play', 'Responsible play', $article['article_markdown']);
        $client = $this->client([$this->decision(), $this->response($article), $this->decision()]);
        $operation = $generated ? 'regenerate' : 'generate';
        $this->postJson("/api/contents/{$content->id}/{$operation}")->assertOk()
            ->assertJsonPath('content.generated_content', $article['article_markdown'])
            ->assertJsonPath('content.generated_meta_title', $article['meta_title'])
            ->assertJsonPath('content.is_generation_stale', false);
        $this->assertSame(1, preg_match_all('/^##[ \t]+\S/m', $content->refresh()->generated_content));
        $this->assertSame(0, preg_match_all('/^###[ \t]+\S/m', $content->generated_content));
        $this->assertSame('completed', AIRequest::sole()->status);
        $client->chat()->assertSent(3);
        $client->chat()->assertSent(fn ($method, $parameters) => ($parameters['response_format']['json_schema']['name'] ?? null) === 'content_moderation'
            && json_decode($parameters['messages'][1]['content'], true) === $article);
    }

    public static function translationsFromUkrainian(): array
    {
        return [['en', 'English', false], ['de', 'German', false], ['en', 'English', true], ['de', 'German', true]];
    }

    #[DataProvider('translationsFromUkrainian')]
    public function test_selected_translation_uses_its_language_despite_copied_ukrainian_inputs(string $language, string $name, bool $generated): void
    {
        $source = $this->content(true, 'uk');
        $source->update([
            'title' => 'Путівник онлайн казино', 'topic' => 'Відповідальна гра та вибір ігор',
            'primary_keyword' => 'онлайн казино', 'secondary_keywords' => ['ігри казино'],
            'meta_title' => 'Путівник казино', 'meta_description' => 'Дізнайтеся про відповідальну гру.',
        ]);
        $beforeSource = $source->refresh()->getAttributes();
        $beforeGroup = $source->contentGroup->getAttributes();
        $id = $this->postJson("/api/contents/{$source->id}/translations", ['content_language' => $language])
            ->assertCreated()->assertJsonPath('content_language', $language)->assertJsonPath('primary_language', 'uk')->json('id');
        $translation = Content::findOrFail($id);
        $this->assertSame($source->title, $translation->title);
        $this->assertSame($source->primary_keyword, $translation->primary_keyword);
        if ($generated) {
            $translation->forceFill([
                'generated_content' => 'Previously saved localized article', 'generated_title' => 'Saved title',
                'generated_meta_title' => 'Saved meta', 'generated_meta_description' => 'Saved description',
                'generation_fingerprint' => $translation->generationInputs()->fingerprint(),
            ])->save();
        }
        $article = $this->article();
        $client = $this->client([$this->decision(), $this->response($article), $this->decision()]);
        $operation = $generated ? 'regenerate' : 'generate';
        $this->postJson("/api/contents/{$id}/{$operation}", ['content_language' => 'uk'])
            ->assertOk()->assertJsonPath('content.id', $id)->assertJsonPath('content.content_language', $language)
            ->assertJsonPath('content.primary_language', 'uk')->assertJsonPath('content.generated_content', $article['article_markdown']);
        $client->chat()->assertSent(function ($method, $parameters) use ($name): bool {
            if (($parameters['response_format']['json_schema']['name'] ?? null) !== 'seo_article') {
                return false;
            }
            $system = $parameters['messages'][0]['content'];
            $this->assertStringContainsString("The generated content must be written in {$name}.", $system);
            $this->assertStringContainsString('The target language takes precedence over the language of the supplied draft and SEO inputs.', $system);
            $this->assertStringContainsString('use natural target-language equivalents', $system);
            $this->assertStringNotContainsString('keep supplied keywords unchanged', $system);
            $this->assertStringContainsString('Путівник онлайн казино', $parameters['messages'][1]['content']);

            return true;
        });
        $client->chat()->assertSent(3);
        $this->assertSame($beforeSource, $source->refresh()->getAttributes());
        $this->assertSame($beforeGroup, $source->contentGroup->refresh()->getAttributes());
        $this->assertSame($translation->generationInputs()->fingerprint(), $translation->refresh()->generation_fingerprint);
        $this->assertFalse($translation->is_generation_stale);
        $this->assertSame($id, AIRequest::sole()->content_id);
    }

    public function test_multiple_supplied_links_preserve_anchors_with_markdown_punctuation(): void
    {
        $content = $this->content();
        $content->update(['links' => [
            ['anchor' => '[game] guide.', 'url' => 'https://example.com/games_(slots)'],
            ['anchor' => 'Second guide!', 'url' => 'http://example.com/second'],
        ]]);
        $article = $this->article();
        $article['article_markdown'] .= "\n\nRead [\\[game\\] guide\\.](<https://example.com/games_(slots)>) and [Second guide!](<http://example.com/second>) for details.";
        $this->client([$this->decision(), $this->response($article), $this->decision()]);
        $this->postJson("/api/contents/{$content->id}/generate")->assertOk()
            ->assertJsonPath('content.generated_content', $article['article_markdown']);
    }

    public function test_seo_edit_during_generation_keeps_snapshot_fingerprint(): void
    {
        $content = $this->content(true);
        $snapshot = $content->generationInputs();
        $duringGeneration = function () use ($content): void {
            $this->patchJson("/api/contents/{$content->id}", ['meta_title' => 'Edited during generation'])
                ->assertOk()->assertJsonPath('generated_meta_title', 'Saved meta');
        };
        $client = new class([$this->decision(), $this->response($this->article()), $this->decision()], $duringGeneration) extends ClientFake
        {
            public function __construct(array $responses, private Closure $duringGeneration)
            {
                parent::__construct($responses);
            }

            public function record(TestRequest $request): ResponseContract|ResponseStreamContract|string
            {
                if (($request->args()[0]['response_format']['json_schema']['name'] ?? null) === 'seo_article') {
                    ($this->duringGeneration)();
                }

                return parent::record($request);
            }
        };
        $this->app->instance(ClientContract::class, $client);
        $this->postJson("/api/contents/{$content->id}/regenerate")->assertOk()
            ->assertJsonPath('content.meta_title', 'Edited during generation')->assertJsonPath('content.is_generation_stale', true);
        $this->assertSame($snapshot->fingerprint(), $content->refresh()->generation_fingerprint);
        $client->chat()->assertSent(fn ($method, $parameters) => $parameters['messages'][1]['content'] === $snapshot->prompt());
    }

    public function test_seo_generation_and_regeneration_in_a_group_preserve_sibling_outputs_and_state(): void
    {
        $source = $this->content(true);
        $uk = app(ManageContent::class)->translate($source->user, (string) $source->id, ContentLanguage::Ukrainian);
        $de = app(ManageContent::class)->translate($source->user, (string) $source->id, ContentLanguage::German);
        $beforeSource = $source->refresh()->getAttributes();
        $beforeUk = $uk->refresh()->getAttributes();
        $this->client([
            $this->decision(), $this->response($this->article()), $this->decision(),
            $this->decision(), $this->response($this->article()), $this->decision(),
        ]);
        $this->postJson("/api/contents/{$de->id}/generate")->assertOk()->assertJsonPath('content.content_language', 'de');
        $this->patchJson("/api/contents/{$de->id}", ['secondary_keywords' => ['roulette']])
            ->assertOk()->assertJsonPath('is_generation_stale', true);
        $response = $this->postJson("/api/contents/{$de->id}/regenerate")->assertOk()
            ->assertJsonPath('content.is_generation_stale', false)->assertJsonPath('content.secondary_keywords', ['roulette']);
        $this->assertSame($beforeSource, $source->refresh()->getAttributes());
        $this->assertSame($beforeUk, $uk->refresh()->getAttributes());
        $response->assertJsonPath('content.translations', [
            ['id' => $source->id, 'content_language' => 'en', 'has_generated_content' => true, 'is_generation_stale' => false],
            ['id' => $uk->id, 'content_language' => 'uk', 'has_generated_content' => false, 'is_generation_stale' => false],
            ['id' => $de->id, 'content_language' => 'de', 'has_generated_content' => true, 'is_generation_stale' => false],
        ]);
    }
}
