<?php

namespace Tests\Feature;

use App\Models\AIRequest;
use App\Models\Content;
use App\Models\User;
use Closure;
use Exception;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\ResponseContract;
use OpenAI\Contracts\ResponseStreamContract;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use OpenAI\Testing\Enums\OverrideStrategy;
use OpenAI\Testing\Requests\TestRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;
use TypeError;

class GenerateContentTest extends TestCase
{
    // Avoid an outer test transaction so the provider boundary can verify commit.
    use DatabaseMigrations;

    private function draft(User $user): Content
    {
        return $user->contents()->create([
            'title' => 'Stored title',
            'topic' => 'Stored topic',
            'tone' => 'Friendly',
            'length' => 'Short',
            'metadata' => ['private' => 'Do not send metadata'],
        ])->refresh();
    }

    private function fake(array $responses): ClientFake
    {
        $client = new ClientFake($responses);
        $this->app->instance(ClientContract::class, $client);

        return $client;
    }

    public function test_guest_cannot_generate(): void
    {
        $content = $this->draft(User::factory()->create());
        $client = $this->fake([]);

        $this->postJson("/api/contents/{$content->id}/generate")->assertUnauthorized();

        $client->assertNothingSent();
        $this->assertDatabaseCount('ai_requests', 0);
        $this->assertNull($content->refresh()->generated_content);
    }

    public function test_foreign_and_nonexistent_contents_both_return_404(): void
    {
        $content = $this->draft(User::factory()->create());
        $client = $this->fake([]);
        $this->actingAs(User::factory()->create(), 'web');

        foreach ([$content->id, $content->id + 100] as $id) {
            $this->postJson("/api/contents/{$id}/generate")->assertNotFound();
        }

        $client->assertNothingSent();
        $this->assertDatabaseCount('ai_requests', 0);
        $this->assertNull($content->refresh()->generated_content);
    }

    public function test_generation_uses_only_stored_data_and_commits_the_complete_result(): void
    {
        config(['services.openai.model' => 'test-generation-model']);
        $user = User::factory()->create();
        $content = $this->draft($user);
        $original = $content->toArray();
        $providerResponse = CreateResponse::fake([
            'choices' => [['message' => ['content' => "  Generated text\n"]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30],
        ]);
        $beforeCall = function () use ($user, $content): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseCount('ai_requests', 1);
            $this->assertDatabaseHas('ai_requests', [
                'user_id' => $user->id, 'content_id' => $content->id, 'status' => 'pending',
            ]);
            $this->assertNull($content->fresh()->generated_content);
        };
        $client = new class([$providerResponse], $beforeCall) extends ClientFake
        {
            public function __construct(array $responses, private Closure $beforeCall)
            {
                parent::__construct($responses);
            }

            public function record(TestRequest $request): ResponseContract|ResponseStreamContract|string
            {
                ($this->beforeCall)();

                return parent::record($request);
            }
        };
        $this->app->instance(ClientContract::class, $client);

        $response = $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/generate", [
            'user_id' => User::factory()->create()->id,
            'title' => 'Injected title', 'topic' => 'Injected topic',
            'tone' => 'Injected tone', 'length' => 'Injected length',
            'generated_content' => 'Injected content', 'metadata' => ['injected' => true],
            'tokens_used' => 999, 'cost' => 999, 'status' => 'failed',
        ])->assertOk();

        $aiRequest = AIRequest::sole();
        $content->refresh();
        $response->assertExactJson([
            'content' => $content->toArray(),
            'ai_request' => ['id' => $aiRequest->id, 'status' => 'completed', 'tokens_used' => 30, 'cost' => null],
        ]);
        $this->assertSame('Generated text', $content->generated_content);
        foreach (['user_id', 'title', 'topic', 'tone', 'length', 'metadata', 'created_at'] as $field) {
            $this->assertSame($original[$field], $content->toArray()[$field]);
        }
        $this->assertDatabaseCount('contents', 1);
        $this->assertSame($user->id, $aiRequest->user_id);
        $this->assertSame($content->id, $aiRequest->content_id);
        $this->assertNull($aiRequest->error_message);
        $this->assertSame(0, $user->refresh()->monthly_generation_count);
        $this->assertSame(0, DB::transactionLevel());
        $client->chat()->assertSent(1);
        $client->chat()->assertSent(fn (string $method, array $parameters): bool => $method === 'create'
            && $parameters === [
                'model' => 'test-generation-model',
                'messages' => [
                    ['role' => 'system', 'content' => 'Write content using the supplied draft details. Return only the generated text.'],
                    ['role' => 'user', 'content' => "Title: Stored title\nTopic: Stored topic\nTone: Friendly\nLength: Short"],
                ],
            ]);
    }

    public function test_missing_usage_and_optional_fields_allow_generation_with_an_empty_body(): void
    {
        $user = User::factory()->create();
        $content = $user->contents()->create(['title' => 'Draft', 'topic' => 'Topic']);
        $attributes = CreateResponse::fake()->toArray();
        unset($attributes['usage']);
        $client = $this->fake([CreateResponse::from($attributes, CreateResponse::fakeResponseMetaInformation())]);

        $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/generate")
            ->assertOk()->assertJsonPath('ai_request.tokens_used', null)->assertJsonPath('ai_request.cost', null);

        $this->assertNull(AIRequest::sole()->tokens_used);
        $this->assertNull(AIRequest::sole()->cost);
        $client->chat()->assertSent(1);
    }

    #[DataProvider('providerFailures')]
    public function test_provider_failures_are_safe_and_leave_content_unchanged(Exception $exception): void
    {
        config(['app.debug' => true]);
        $user = User::factory()->create();
        $content = $this->draft($user);
        $original = $content->toArray();
        $client = $this->fake([$exception]);

        $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/generate")
            ->assertStatus(503)->assertExactJson(['error' => 'AI service unavailable'])
            ->assertHeaderMissing('X-Upstream-Secret');

        $this->assertSame($original, $content->refresh()->toArray());
        $this->assertSame('failed', AIRequest::sole()->status);
        $this->assertSame('Provider request failed', AIRequest::sole()->error_message);
        $this->assertNull(AIRequest::sole()->tokens_used);
        $this->assertNull(AIRequest::sole()->cost);
        $this->assertSame(0, $user->refresh()->monthly_generation_count);
        $client->chat()->assertSent(1);
    }

    public static function providerFailures(): array
    {
        return AIEndpointTest::providerFailures();
    }

    #[DataProvider('unusableResponses')]
    public function test_unusable_content_marks_the_request_failed(array $choices): void
    {
        $user = User::factory()->create();
        $content = $this->draft($user);
        $original = $content->toArray();
        $client = $this->fake([CreateResponse::fake([
            'choices' => $choices,
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 0, 'total_tokens' => 10],
        ], strategy: OverrideStrategy::Replace)]);

        $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/generate")
            ->assertStatus(503)->assertExactJson(['error' => 'AI service unavailable']);

        $this->assertSame($original, $content->refresh()->toArray());
        $this->assertSame('failed', AIRequest::sole()->status);
        $this->assertSame('Provider returned unusable content', AIRequest::sole()->error_message);
        $this->assertSame(10, AIRequest::sole()->tokens_used);
        $client->chat()->assertSent(1);
    }

    public static function unusableResponses(): array
    {
        $cases = AIEndpointTest::missingContentResponses();
        foreach (['empty' => '', 'whitespace' => " \n\t"] as $name => $text) {
            $cases[$name] = [[['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text]]]];
        }

        return $cases;
    }

    #[DataProvider('blockedStates')]
    public function test_ineligible_content_returns_409_without_calling_the_provider(?string $text, bool $pending): void
    {
        $user = User::factory()->create();
        $content = $this->draft($user);
        $content->update(['generated_content' => $text]);
        if ($pending) {
            $user->aiRequests()->create(['content_id' => $content->id, 'status' => 'pending']);
        }
        $client = $this->fake([]);

        $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertConflict();

        $client->assertNothingSent();
        $this->assertDatabaseCount('ai_requests', $pending ? 1 : 0);
        $this->assertSame($text, $content->refresh()->generated_content);
    }

    public static function blockedStates(): array
    {
        return ['generated' => ['Existing text', false], 'non-null empty text' => ['', false], 'pending' => [null, true]];
    }

    public function test_a_failed_previous_request_allows_a_new_attempt(): void
    {
        $user = User::factory()->create();
        $content = $this->draft($user);
        $previous = $user->aiRequests()->create([
            'content_id' => $content->id, 'status' => 'failed', 'error_message' => 'Provider request failed',
        ]);
        $client = $this->fake([CreateResponse::fake()]);

        $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertOk();

        $this->assertDatabaseCount('ai_requests', 2);
        $this->assertSame('failed', $previous->refresh()->status);
        $this->assertDatabaseHas('ai_requests', ['content_id' => $content->id, 'status' => 'completed']);
        $client->chat()->assertSent(1);
    }

    #[DataProvider('persistenceFailures')]
    public function test_final_database_failures_rollback_and_attempt_to_mark_failed(string $table, string $condition, string $expectedStatus): void
    {
        config(['app.debug' => true]);
        $user = User::factory()->create();
        $content = $this->draft($user);
        $original = $content->toArray();
        $client = $this->fake([CreateResponse::fake()]);
        DB::unprepared("CREATE TRIGGER fail_generation BEFORE UPDATE ON {$table} WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Sensitive SQL failure'); END");

        try {
            $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/generate")
                ->assertStatus(500)->assertExactJson(['error' => 'Unable to save generated content']);

            $this->assertSame($original, $content->refresh()->toArray());
            $this->assertSame($expectedStatus, AIRequest::sole()->status);
            $this->assertSame($expectedStatus === 'failed' ? 'Failed to persist generation' : null, AIRequest::sole()->error_message);
            $this->assertSame(0, DB::transactionLevel());
            $client->chat()->assertSent(1);
        } finally {
            DB::unprepared('DROP TRIGGER fail_generation');
        }
    }

    public static function persistenceFailures(): array
    {
        return [
            'content save fails' => ['contents', 'NEW.generated_content IS NOT NULL', 'failed'],
            'completion save fails after content save' => ['ai_requests', "NEW.status = 'completed'", 'failed'],
            'completion and cleanup both fail' => ['ai_requests', '1 = 1', 'pending'],
        ];
    }

    #[DataProvider('programmingErrors')]
    public function test_persistence_programming_errors_are_not_converted_to_database_failures(string $event, Throwable $failure): void
    {
        $this->withoutExceptionHandling();
        $user = User::factory()->create();
        $content = $this->draft($user);
        $client = $this->fake([CreateResponse::fake()]);
        $dispatcher = AIRequest::getEventDispatcher();
        AIRequest::setEventDispatcher(clone $dispatcher);
        AIRequest::$event(static function () use ($failure): void {
            throw $failure;
        });

        $this->expectException($failure::class);
        $this->expectExceptionMessage($failure->getMessage());

        try {
            $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/generate");
        } finally {
            AIRequest::setEventDispatcher($dispatcher);
            $this->assertNull($content->refresh()->generated_content);
            $this->assertSame(0, DB::transactionLevel());
            if ($event === 'creating') {
                $this->assertDatabaseCount('ai_requests', 0);
                $client->assertNothingSent();
            } else {
                $this->assertSame('pending', AIRequest::sole()->status);
                $client->chat()->assertSent(1);
            }
        }
    }

    public static function programmingErrors(): array
    {
        return [
            'initial LogicException' => ['creating', new LogicException('Programming error')],
            'initial TypeError' => ['creating', new TypeError('Programming error')],
            'final LogicException' => ['updating', new LogicException('Programming error')],
            'final TypeError' => ['updating', new TypeError('Programming error')],
        ];
    }

    public function test_failure_to_create_pending_request_does_not_call_the_provider(): void
    {
        config(['app.debug' => true]);
        $user = User::factory()->create();
        $content = $this->draft($user);
        $client = $this->fake([]);
        DB::unprepared("CREATE TRIGGER fail_pending BEFORE INSERT ON ai_requests BEGIN SELECT RAISE(ABORT, 'Sensitive SQL failure'); END");

        try {
            $this->actingAs($user, 'web')->postJson("/api/contents/{$content->id}/generate")
                ->assertStatus(500)->assertExactJson(['error' => 'Unable to save generated content']);

            $this->assertDatabaseCount('ai_requests', 0);
            $this->assertNull($content->refresh()->generated_content);
            $client->assertNothingSent();
        } finally {
            DB::unprepared('DROP TRIGGER fail_pending');
        }
    }
}
