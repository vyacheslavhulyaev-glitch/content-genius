<?php

namespace Tests\Feature;

use App\Actions\ManageContent;
use App\Enums\ContentLanguage;
use App\Models\AIRequest;
use App\Models\Content;
use App\Models\User;
use App\Services\ContentModerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\ResponseContract;
use OpenAI\Contracts\ResponseStreamContract;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use OpenAI\Testing\Requests\TestRequest;
use Tests\Support\SeoArticleResponse;
use Tests\TestCase;

class GenerationQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function client(int $pipelines): ClientFake
    {
        $allowed = CreateResponse::fake(['choices' => [['message' => [
            'content' => json_encode(array_fill_keys(ContentModerator::CATEGORIES, false)),
        ]]]]);
        $responses = [];
        for ($i = 0; $i < $pipelines; $i++) {
            array_push($responses, $allowed, SeoArticleResponse::fake(), $allowed);
        }
        $client = new ClientFake($responses);
        $this->app->instance(ClientContract::class, $client);

        return $client;
    }

    public function test_five_shared_generate_regenerate_requests_then_429_without_any_provider_calls(): void
    {
        $this->freezeTime();
        $content = Content::factory()->create()->refresh();
        $client = $this->client(6);
        $this->actingAs($content->user, 'web');
        for ($i = 0; $i < 5; $i++) {
            $operation = $i === 0 ? 'generate' : 'regenerate';
            $this->postJson("/api/contents/{$content->id}/{$operation}")->assertOk();
        }
        $before = $content->refresh()->getAttributes();
        $this->postJson("/api/contents/{$content->id}/regenerate")->assertStatus(429)
            ->assertJsonPath('code', 'generation_rate_limited')->assertJsonPath('retry_after', 3600)
            ->assertHeader('Retry-After', '3600')->assertHeader('X-RateLimit-Remaining', '0')
            ->assertJsonPath('reset_at', now()->addHour()->format('Y-m-d\TH:i:s\Z'));
        $otherVersion = app(ManageContent::class)->translate($content->user, (string) $content->id, ContentLanguage::German);
        $this->postJson("/api/contents/{$otherVersion->id}/generate")->assertStatus(429);
        $client->chat()->assertSent(15);
        $this->assertDatabaseCount('ai_requests', 5);
        $this->assertDatabaseCount('provider_calls', 15);
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->travel(1)->hours();
        $this->postJson("/api/contents/{$content->id}/regenerate")->assertOk();
        $client->chat()->assertSent(18);
    }

    public function test_crud_does_not_consume_quota_and_deleting_content_does_not_reset_it(): void
    {
        config(['generation.quota.requests' => 1]);
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $id = $this->postJson('/api/contents', ['title' => 'SEO guide', 'topic' => 'SEO article'])->assertCreated()->json('id');
        $this->patchJson("/api/contents/{$id}", ['tone' => 'Friendly'])->assertOk();
        $this->getJson('/api/contents')->assertOk();
        $this->assertDatabaseCount('ai_requests', 0);
        $client = $this->client(1);
        $this->postJson("/api/contents/{$id}/generate")->assertOk();
        $this->deleteJson("/api/contents/{$id}")->assertNoContent();
        $next = Content::factory()->for($user)->create();
        $this->postJson("/api/contents/{$next->id}/generate")->assertStatus(429);
        $client->chat()->assertSent(3);
        $this->assertDatabaseCount('ai_requests', 1);
        $this->assertNull(AIRequest::sole()->content_id);
    }

    public function test_users_have_independent_quotas_and_invalid_requests_do_not_reserve_slots(): void
    {
        config(['generation.quota.requests' => 1]);
        $first = Content::factory()->create();
        $second = Content::factory()->create();
        $client = $this->client(2);
        $this->actingAs($first->user, 'web')->postJson("/api/contents/{$second->id}/generate")->assertNotFound();
        $this->postJson("/api/contents/{$first->id}/regenerate")->assertStatus(409);
        $this->assertDatabaseCount('ai_requests', 0);
        $this->postJson("/api/contents/{$first->id}/generate")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($second->user, 'web')->postJson("/api/contents/{$second->id}/generate")->assertOk();
        $client->chat()->assertSent(6);
    }

    public function test_quota_reservation_exists_before_input_moderation_and_blocks_another_content(): void
    {
        config(['generation.quota.requests' => 1]);
        $first = Content::factory()->create();
        $second = Content::factory()->for($first->user)->create();
        $blocked = false;
        $duringRequest = function () use ($second, &$blocked): void {
            $this->assertSame('pending', AIRequest::sole()->status);
            $this->postJson("/api/contents/{$second->id}/generate")->assertStatus(429);
            $blocked = true;
        };
        $client = new class($duringRequest) extends ClientFake
        {
            private bool $checked = false;

            public function __construct(private \Closure $duringRequest)
            {
                $allowed = CreateResponse::fake(['choices' => [['message' => ['content' => json_encode(array_fill_keys(ContentModerator::CATEGORIES, false))]]]]);
                parent::__construct([$allowed, SeoArticleResponse::fake(), $allowed]);
            }

            public function record(TestRequest $request): ResponseContract|ResponseStreamContract|string
            {
                if (! $this->checked) {
                    $this->checked = true;
                    ($this->duringRequest)();
                }

                return parent::record($request);
            }
        };
        $this->app->instance(ClientContract::class, $client);
        $this->actingAs($first->user, 'web')->postJson("/api/contents/{$first->id}/generate")->assertOk();
        $this->assertTrue($blocked);
        $client->chat()->assertSent(3);
    }

    public function test_quota_uses_a_rolling_hour_instead_of_resetting_at_clock_boundaries(): void
    {
        $this->freezeTime();
        $content = Content::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $request = $content->user->aiRequests()->create(['content_id' => $content->id, 'status' => 'failed']);
            $request->forceFill(['created_at' => now()->subMinutes(59 - $i)])->save();
        }
        $client = $this->client(1);
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertStatus(429)->assertJsonPath('retry_after', 60);
        $client->assertNothingSent();
        $this->travel(60)->seconds();
        $this->postJson("/api/contents/{$content->id}/generate")->assertOk();
        $this->postJson("/api/contents/{$content->id}/regenerate")->assertStatus(429)->assertJsonPath('retry_after', 60);
        $client->chat()->assertSent(3);
    }

    public function test_a_paid_moderation_failure_consumes_a_slot_and_preserves_saved_output(): void
    {
        config(['generation.quota.requests' => 1]);
        $content = Content::factory()->create(['generated_content' => 'Saved body'])->refresh();
        $before = $content->getAttributes();
        $client = new ClientFake([new \RuntimeException('Private provider exception')]);
        $this->app->instance(ClientContract::class, $client);
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/regenerate")->assertStatus(503)
            ->assertJsonPath('code', 'moderation_unavailable');
        $this->postJson("/api/contents/{$content->id}/regenerate")->assertStatus(429);
        $client->chat()->assertSent(1);
        $this->assertSame($before, $content->refresh()->getAttributes());
    }
}
