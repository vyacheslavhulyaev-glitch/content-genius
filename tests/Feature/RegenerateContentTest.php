<?php

namespace Tests\Feature;

use App\Actions\GenerateContent;
use App\Models\AIRequest;
use App\Models\Content;
use App\Models\User;
use App\Support\GenerationInputs;
use Closure;
use Exception;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\ResponseContract;
use OpenAI\Contracts\ResponseStreamContract;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use OpenAI\Testing\Enums\OverrideStrategy;
use OpenAI\Testing\Requests\TestRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegenerateContentTest extends TestCase
{
    use DatabaseMigrations;

    private function content(User $user): Content
    {
        $content = $user->contents()->create([
            'title' => 'Title', 'topic' => 'Topic', 'tone' => 'Professional', 'length' => 'Short',
            'generated_content' => 'Old text',
        ]);
        $content->forceFill(['generation_fingerprint' => $content->generationInputs()->fingerprint()])->save();

        return $content->refresh();
    }

    private function fake(array $responses, ?Closure $duringCall = null): ClientFake
    {
        $client = new class($responses, $duringCall) extends ClientFake
        {
            public function __construct(array $responses, private ?Closure $duringCall)
            {
                parent::__construct($responses);
            }

            public function record(TestRequest $request): ResponseContract|ResponseStreamContract|string
            {
                if ($this->duringCall !== null) {
                    ($this->duringCall)();
                }

                return parent::record($request);
            }
        };
        $this->app->instance(ClientContract::class, $client);

        return $client;
    }

    public function test_guest_and_foreign_or_missing_content_are_rejected(): void
    {
        $content = $this->content(User::factory()->create());
        $client = $this->fake([]);
        $this->postJson("/api/contents/{$content->id}/regenerate")->assertUnauthorized();
        $this->actingAs(User::factory()->admin()->create(), 'web');
        foreach ([$content->id, $content->id + 100] as $id) {
            $this->postJson("/api/contents/{$id}/regenerate")->assertNotFound();
        }
        $client->assertNothingSent();
    }

    public function test_drafts_and_pending_requests_cannot_be_regenerated(): void
    {
        $user = User::factory()->create();
        $draft = $user->contents()->create(['title' => 'Draft', 'topic' => 'Topic']);
        $generated = $this->content($user);
        $user->aiRequests()->create(['content_id' => $generated->id, 'status' => 'pending']);
        $client = $this->fake([]);
        $this->actingAs($user, 'web');
        foreach ([$draft, $generated] as $content) {
            $this->postJson("/api/contents/{$content->id}/regenerate")->assertConflict();
        }
        $this->assertDatabaseCount('ai_requests', 1);
        $client->assertNothingSent();
    }

    public function test_success_replaces_text_and_fingerprint_and_keeps_history(): void
    {
        $user = User::factory()->create();
        $content = $this->content($user);
        $oldFingerprint = $content->generation_fingerprint;
        $old = $user->aiRequests()->create(['content_id' => $content->id, 'status' => 'completed', 'tokens_used' => 9]);
        $oldAttributes = $old->refresh()->getAttributes();
        $content->update(['tone' => 'Casual']);
        $client = $this->fake([CreateResponse::fake([
            'choices' => [['message' => ['content' => '  New text  ']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ])], function () use ($content, $oldFingerprint): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('Old text', $content->fresh()->generated_content);
            $this->assertSame($oldFingerprint, $content->fresh()->generation_fingerprint);
            $this->assertDatabaseHas('ai_requests', ['content_id' => $content->id, 'status' => 'pending']);
        });
        $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/regenerate", [
            'title' => 'Injected', 'generated_content' => 'Injected', 'generation_fingerprint' => 'Injected',
        ])->assertOk()->assertJsonPath('content.generated_content', 'New text')
            ->assertJsonPath('content.is_generation_stale', false)->assertJsonMissingPath('content.generation_fingerprint')
            ->assertJsonPath('ai_request.status', 'completed')->assertJsonPath('ai_request.tokens_used', 30);
        $this->assertNotSame($oldFingerprint, $content->refresh()->generation_fingerprint);
        $this->assertSame($content->generationInputs()->fingerprint(), $content->generation_fingerprint);
        $this->assertSame($oldAttributes, $old->refresh()->getAttributes());
        $this->assertDatabaseCount('ai_requests', 2);
        $client->chat()->assertSent(fn (string $method, array $parameters): bool => $parameters['messages'][1]['content'] === "Title: Title\nTopic: Topic\nTone: Casual\nLength: Short");
    }

    #[DataProvider('generationModes')]
    public function test_input_snapshot_preserves_edits_made_during_provider_call(string $mode): void
    {
        $user = User::factory()->create();
        $content = $this->content($user);
        if ($mode === 'generate') {
            $content->forceFill(['generated_content' => null, 'generation_fingerprint' => null])->save();
        }
        $snapshot = $content->generationInputs();
        $client = $this->fake([CreateResponse::fake()], function () use ($content): void {
            $this->assertSame(0, DB::transactionLevel());
            Content::findOrFail($content->id)->update(['title' => 'New title', 'tone' => 'Casual']);
        });
        $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/{$mode}")->assertOk()
            ->assertJsonPath('content.title', 'New title')->assertJsonPath('content.tone', 'Casual')
            ->assertJsonPath('content.is_generation_stale', true)->assertJsonMissingPath('content.generation_fingerprint');
        $this->assertSame($snapshot->fingerprint(), $content->refresh()->generation_fingerprint);
        $this->assertSame('Casual', $content->tone);
        $client->chat()->assertSent(fn (string $method, array $parameters): bool => $parameters['messages'][1]['content'] === $snapshot->prompt());
        $this->getJson('/api/contents')->assertJsonPath('0.is_generation_stale', true);
    }

    public static function generationModes(): array
    {
        return [['generate'], ['regenerate']];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failure_preserves_old_text_and_fingerprint(Exception $failure): void
    {
        config(['app.debug' => true]);
        $user = User::factory()->create();
        $content = $this->content($user);
        $content->update(['topic' => 'Edited topic']);
        $before = $content->getAttributes();
        $this->fake([$failure]);
        $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/regenerate")
            ->assertStatus(503)->assertExactJson(['error' => 'AI service unavailable'])->assertHeaderMissing('X-Upstream-Secret');
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertTrue($content->is_generation_stale);
        $this->assertSame('failed', AIRequest::sole()->status);
    }

    public static function providerFailures(): array
    {
        return GenerateContentTest::providerFailures();
    }

    #[DataProvider('unusableResponses')]
    public function test_unusable_response_preserves_previous_generation(array $choices): void
    {
        $user = User::factory()->create();
        $content = $this->content($user);
        $before = $content->getAttributes();
        $this->fake([CreateResponse::fake(['choices' => $choices], strategy: OverrideStrategy::Replace)]);
        $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/regenerate")
            ->assertStatus(503)->assertExactJson(['error' => 'AI service unavailable']);
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertSame('failed', AIRequest::sole()->status);
    }

    public static function unusableResponses(): array
    {
        return GenerateContentTest::unusableResponses();
    }

    #[DataProvider('persistenceFailures')]
    public function test_final_database_failure_rolls_back_text_and_fingerprint(string $table, string $condition, string $status): void
    {
        $user = User::factory()->create();
        $content = $this->content($user);
        $content->update(['length' => 'Long']);
        $before = $content->getAttributes();
        $this->fake([CreateResponse::fake()]);
        DB::unprepared("CREATE TRIGGER fail_regeneration BEFORE UPDATE ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Private SQL details'); END");
        try {
            $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/regenerate")
                ->assertStatus(500)->assertExactJson(['error' => 'Unable to save generated content']);
            $this->assertSame($before, $content->refresh()->getAttributes());
            $this->assertTrue($content->is_generation_stale);
            $this->assertSame($status, AIRequest::sole()->status);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            DB::unprepared('DROP TRIGGER fail_regeneration');
        }
    }

    public static function persistenceFailures(): array
    {
        return GenerateContentTest::persistenceFailures();
    }

    public function test_missing_usage_is_allowed(): void
    {
        $user = User::factory()->create();
        $content = $this->content($user);
        $attributes = CreateResponse::fake()->toArray();
        unset($attributes['usage']);
        $this->fake([CreateResponse::from($attributes, CreateResponse::fakeResponseMetaInformation())]);
        $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/regenerate")
            ->assertOk()->assertJsonPath('ai_request.tokens_used', null);
    }

    public function test_failure_to_create_pending_request_keeps_previous_generation(): void
    {
        $user = User::factory()->create();
        $content = $this->content($user);
        $before = $content->getAttributes();
        $client = $this->fake([]);
        DB::unprepared("CREATE TRIGGER fail_pending_regeneration BEFORE INSERT ON ai_requests BEGIN SELECT RAISE(ABORT, 'Private SQL details'); END");
        try {
            $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/regenerate")
                ->assertStatus(500)->assertExactJson(['error' => 'Unable to save generated content']);
            $this->assertSame($before, $content->refresh()->getAttributes());
            $this->assertDatabaseCount('ai_requests', 0);
            $client->assertNothingSent();
        } finally {
            DB::unprepared('DROP TRIGGER fail_pending_regeneration');
        }
    }

    #[DataProvider('generationModes')]
    public function test_second_generation_is_rejected_while_provider_request_is_in_progress(string $mode): void
    {
        $user = User::factory()->create();
        $content = $this->content($user);
        if ($mode === 'generate') {
            $content->update(['generated_content' => null]);
        }
        $client = $this->fake([CreateResponse::fake()], function () use ($user, $content, $mode): void {
            $response = app(GenerateContent::class)($user, (string) $content->id, app(ClientContract::class), $mode === 'regenerate');
            $this->assertSame(409, $response->getStatusCode());
            $this->assertDatabaseCount('ai_requests', 1);
        });
        $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/{$mode}")->assertOk();
        $client->chat()->assertSent(1);
    }

    #[DataProvider('generationModes')]
    public function test_deletion_during_generation_does_not_resurrect_content(string $mode): void
    {
        $user = User::factory()->create();
        $content = $this->content($user);
        if ($mode === 'generate') {
            $content->update(['generated_content' => null]);
        }
        $this->fake([CreateResponse::fake()], fn () => $content->delete());
        $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/{$mode}")->assertNotFound();
        $this->assertDatabaseMissing('contents', ['id' => $content->id]);
        $this->assertSame('failed', AIRequest::sole()->status);
        $this->assertNull(AIRequest::sole()->content_id);
    }

    public function test_fingerprint_normalizes_null_empty_and_whitespace_consistently(): void
    {
        $first = new GenerationInputs(' Title ', ' Topic ', null, '');
        $second = new GenerationInputs('Title', 'Topic', '', '  ');
        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $this->assertSame($first->prompt(), $second->prompt());
    }
}
