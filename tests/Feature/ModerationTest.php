<?php

namespace Tests\Feature;

use App\Models\AIRequest;
use App\Models\Content;
use App\Services\ContentModerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\SeoArticleResponse;
use Tests\TestCase;

class ModerationTest extends TestCase
{
    use RefreshDatabase;

    private function decision(?string $blocked = null): CreateResponse
    {
        $categories = array_fill_keys(ContentModerator::CATEGORIES, false);
        if ($blocked !== null) {
            $categories[$blocked] = true;
        }

        return CreateResponse::fake(['choices' => [['message' => ['content' => json_encode($categories)]]]]);
    }

    private function client(array $responses): ClientFake
    {
        $client = new ClientFake($responses);
        $this->app->instance(ClientContract::class, $client);

        return $client;
    }

    private function draft(bool $generated = false): Content
    {
        $content = Content::factory()->create([
            'title' => 'Casino guide', 'topic' => 'Gambling, casino and betting strategies',
            'generated_content' => $generated ? 'Previously approved text' : null,
        ]);
        if ($generated) {
            $content->forceFill(['generation_fingerprint' => $content->generationInputs()->fingerprint()])->save();
        }
        $this->actingAs($content->user, 'web');

        return $content->refresh();
    }

    public static function categories(): array
    {
        return array_map(fn ($category) => [$category], array_combine(ContentModerator::CATEGORIES, ContentModerator::CATEGORIES));
    }

    public static function outputCases(): array
    {
        $cases = [];
        foreach (ContentModerator::CATEGORIES as $category) {
            foreach ([false, true] as $generated) {
                $cases[$category.($generated ? ' regeneration' : ' generation')] = [$category, $generated];
            }
        }

        return $cases;
    }

    #[DataProvider('categories')]
    public function test_blocked_input_never_calls_generation_and_preserves_saved_text(string $category): void
    {
        $content = $this->draft(true);
        $original = $content->getAttributes();
        $client = $this->client([$this->decision($category)]);

        $this->postJson("/api/contents/{$content->id}/regenerate")
            ->assertUnprocessable()->assertJsonPath('code', 'moderation_input_blocked');

        $client->chat()->assertSent(1);
        $client->chat()->assertNotSent(fn ($method, $parameters) => ($parameters['response_format']['json_schema']['name'] ?? null) === 'seo_article');
        $this->assertSame($original, $content->refresh()->getAttributes());
        $this->assertSame('failed', AIRequest::sole()->status);
        $this->assertSame('Input blocked by moderation', AIRequest::sole()->error_message);
    }

    public function test_blocked_first_generation_leaves_a_draft_and_can_be_retried(): void
    {
        $content = $this->draft();
        $client = $this->client([
            $this->decision('profanity'), $this->decision(),
            SeoArticleResponse::fake(), $this->decision(),
        ]);
        $this->postJson("/api/contents/{$content->id}/generate")->assertUnprocessable();
        $this->assertNull($content->refresh()->generated_content);
        $this->assertNull($content->generation_fingerprint);
        $this->postJson("/api/contents/{$content->id}/generate")->assertOk();
        $client->chat()->assertSent(4);
        $this->assertDatabaseHas('ai_requests', ['content_id' => $content->id, 'status' => 'completed']);
    }

    #[DataProvider('outputCases')]
    public function test_blocked_output_is_not_persisted_or_returned(string $category, bool $generated): void
    {
        $content = $this->draft($generated);
        $original = $content->getAttributes();
        $client = $this->client([
            $this->decision(),
            SeoArticleResponse::fake(['choices' => [['message' => ['content' => 'Blocked generated text']]]]),
            $this->decision($category),
        ]);

        $operation = $generated ? 'regenerate' : 'generate';
        $this->postJson("/api/contents/{$content->id}/{$operation}")
            ->assertUnprocessable()->assertExactJson(['error' => 'Output blocked by moderation', 'code' => 'moderation_output_blocked']);

        $this->assertSame($original, $content->refresh()->getAttributes());
        $this->assertSame('failed', AIRequest::sole()->status);
        $this->assertNotNull(AIRequest::sole()->tokens_used);
        $client->chat()->assertSent(3);
    }

    public static function failures(): array
    {
        return ['input moderation' => ['input'], 'generation' => ['generation'], 'output moderation' => ['output']];
    }

    #[DataProvider('failures')]
    public function test_external_failures_are_safe_and_preserve_saved_content(string $stage): void
    {
        config(['app.debug' => true]);
        $content = $this->draft(true);
        $original = $content->getAttributes();
        $responses = $stage === 'input' ? [] : [$this->decision()];
        if ($stage === 'output') {
            $responses[] = SeoArticleResponse::fake();
        }
        $responses[] = new RuntimeException('Sensitive provider secret');
        $client = $this->client($responses);

        $response = $this->postJson("/api/contents/{$content->id}/regenerate")->assertStatus(503);
        $response->assertExactJson($stage === 'generation'
            ? ['error' => 'AI service unavailable']
            : ['error' => 'Moderation service unavailable', 'code' => 'moderation_unavailable']);
        $this->assertSame($original, $content->refresh()->getAttributes());
        $this->assertSame('failed', AIRequest::sole()->status);
        $this->assertStringNotContainsString('Sensitive', AIRequest::sole()->error_message);
        $client->chat()->assertSent(count($responses));
    }

    public static function malformedDecisions(): array
    {
        return [
            'invalid JSON' => ['not JSON', 'stop'],
            'missing categories' => ['{}', 'stop'],
            'wrong types' => ['{"profanity":"false","explicit_sexual":false,"hate_harassment":false,"dangerous_illegal_instructions":false}', 'stop'],
            'extra category' => ['{"profanity":false,"explicit_sexual":false,"hate_harassment":false,"dangerous_illegal_instructions":false,"gambling":true}', 'stop'],
            'truncated' => ['{"profanity":false,"explicit_sexual":false,"hate_harassment":false,"dangerous_illegal_instructions":false}', 'length'],
            'refusal' => [null, 'stop'],
        ];
    }

    #[DataProvider('malformedDecisions')]
    public function test_unusable_moderation_fails_closed(?string $text, string $finishReason): void
    {
        $content = $this->draft(true);
        $original = $content->getAttributes();
        $client = $this->client([CreateResponse::fake([
            'choices' => [['message' => ['content' => $text], 'finish_reason' => $finishReason]],
        ])]);
        $this->postJson("/api/contents/{$content->id}/regenerate")
            ->assertStatus(503)->assertJsonPath('code', 'moderation_unavailable');
        $this->assertSame($original, $content->refresh()->getAttributes());
        $this->assertSame('failed', AIRequest::sole()->status);
        $client->chat()->assertSent(1);
    }

    public function test_allowed_gambling_uses_input_generation_output_order_for_all_group_languages(): void
    {
        config(['moderation.model' => 'test-moderation', 'services.openai.model' => 'test-generation']);
        $source = $this->draft(true);
        $versions = [$source];
        foreach (['uk', 'de'] as $language) {
            $id = $this->postJson("/api/contents/{$source->id}/translations", ['content_language' => $language])
                ->assertCreated()->json('id');
            $versions[] = Content::findOrFail($id);
        }
        $responses = [];
        foreach ($versions as $version) {
            array_push($responses, $this->decision(), SeoArticleResponse::fake([
                'choices' => [['message' => ['content' => 'Casino and betting guide '.$version->content_language->value]]],
            ]), $this->decision());
        }
        $client = $this->client($responses);
        $calls = [];
        foreach ($versions as $version) {
            $operation = $version->generated_content === null ? 'generate' : 'regenerate';
            $this->postJson("/api/contents/{$version->id}/{$operation}")->assertOk()
                ->assertJsonPath('content.content_group_id', $source->content_group_id)
                ->assertJsonPath('content.content_language', $version->content_language->value);
            array_push($calls, $version->generationInputs()->prompt(), null, json_encode(SeoArticleResponse::payload('Casino and betting guide '.$version->content_language->value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $client->chat()->assertSent(9);
        $index = 0;
        $client->chat()->assertSent(function ($method, $parameters) use (&$index, $calls): bool {
            $expected = $calls[$index++];
            if ($expected === null) {
                $this->assertSame('test-generation', $parameters['model']);
            } else {
                $this->assertSame('test-moderation', $parameters['model']);
                $this->assertSame($expected, $parameters['messages'][1]['content']);
                $this->assertStringContainsString('Gambling, casino and betting content MUST remain allowed', $parameters['messages'][0]['content']);
                $this->assertTrue($parameters['response_format']['json_schema']['strict']);
            }

            return true;
        });
        $this->assertSame(9, $index);
        $this->assertSame(3, AIRequest::where('status', 'completed')->count());
    }

    public function test_ownership_and_validation_precede_moderation(): void
    {
        $content = $this->draft();
        $client = $this->client([]);
        $this->postJson('/api/contents/999999/generate')->assertNotFound();
        $foreign = Content::factory()->create();
        $this->postJson("/api/contents/{$foreign->id}/generate")->assertNotFound();
        $content->update(['title' => '']);
        $this->postJson("/api/contents/{$content->id}/generate")->assertUnprocessable()->assertJsonValidationErrors('title');
        $client->assertNothingSent();
        $this->assertDatabaseCount('ai_requests', 0);
    }
}
