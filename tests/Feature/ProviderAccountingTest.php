<?php

namespace Tests\Feature;

use App\Models\AIRequest;
use App\Models\Content;
use App\Models\ProviderCall;
use App\Services\AiPricing;
use App\Services\ContentModerator;
use App\Services\TrackedOpenAI;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenAI\Contracts\ClientContract;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\SeoArticleResponse;
use Tests\TestCase;

class ProviderAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.model' => 'gpt-5.6-terra', 'moderation.model' => 'gpt-4o-mini']);
    }

    private function decision(int $input, int $output): CreateResponse
    {
        return CreateResponse::fake([
            'model' => 'gpt-4o-mini-2024-07-18',
            'choices' => [['message' => ['content' => json_encode(array_fill_keys(ContentModerator::CATEGORIES, false))]]],
            'usage' => ['prompt_tokens' => $input, 'completion_tokens' => $output, 'total_tokens' => $input + $output],
        ]);
    }

    public function test_each_pipeline_stage_is_accounted_once_with_its_own_model_and_prices(): void
    {
        $content = Content::factory()->create();
        $client = new ClientFake([$this->decision(100, 20), SeoArticleResponse::fake([
            'model' => 'gpt-5.6-terra', 'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 300, 'total_tokens' => 500],
        ]), $this->decision(400, 30)]);
        $this->app->instance(ClientContract::class, $client);
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertOk();
        $this->assertDatabaseCount('ai_requests', 1);
        $calls = AIRequest::sole()->providerCalls()->orderBy('id')->get();
        $this->assertCount(3, $calls);
        $this->assertSame(['input_moderation', 'generation', 'output_moderation'], $calls->pluck('operation')->all());
        $this->assertSame([100, 200, 400], $calls->pluck('input_tokens')->all());
        $this->assertSame([20, 300, 30], $calls->pluck('output_tokens')->all());
        $this->assertSame([120, 500, 430], $calls->pluck('total_tokens')->all());
        $this->assertSame(['0.00002700', '0.00400000', '0.00007800'], $calls->pluck('estimated_cost')->all());
        $this->assertSame(1050, $calls->sum('total_tokens'));
        $this->assertEqualsWithDelta(0.004105, $calls->sum('estimated_cost'), 0.000000001);
        foreach ($calls as $call) {
            $this->assertSame('openai', $call->provider);
            $this->assertSame('completed', $call->status);
            $this->assertSame('USD', $call->currency);
            $this->assertSame($content->id, $call->content_id);
            $this->assertNotNull($call->finished_at);
        }
        $client->chat()->assertSent(3);
        // The legacy field still describes generation alone; analytics never add it to provider totals.
        $this->assertSame(500, AIRequest::sole()->tokens_used);
    }

    public static function uncertainUsage(): array
    {
        return [
            'missing usage' => [null, null, null, null, null],
            'partial usage' => [['prompt_tokens' => 10, 'completion_tokens' => null, 'total_tokens' => 15], 10, null, 15, null],
            'zero output' => [['prompt_tokens' => 10, 'completion_tokens' => 0, 'total_tokens' => 10], 10, 0, 10, '0.00002000'],
            'inconsistent provider total' => [['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 999], 10, 20, 30, '0.00026000'],
        ];
    }

    #[DataProvider('uncertainUsage')]
    public function test_missing_partial_and_zero_usage_are_not_fabricated(?array $usage, ?int $input, ?int $output, ?int $total, ?string $cost): void
    {
        $request = Content::factory()->create()->user->aiRequests()->create([]);
        $response = CreateResponse::fake()->toArray();
        unset($response['usage']);
        if ($usage !== null) {
            $response['usage'] = $usage;
        }
        $client = new ClientFake([CreateResponse::from($response, CreateResponse::fakeResponseMetaInformation())]);
        app(TrackedOpenAI::class)->chat($client, $request, 'generation', ['model' => 'gpt-5.6-terra', 'max_completion_tokens' => 50]);
        $call = ProviderCall::sole();
        $this->assertSame($input, $call->input_tokens);
        $this->assertSame($output, $call->output_tokens);
        $this->assertSame($total, $call->total_tokens);
        $this->assertSame($cost, $call->estimated_cost);
        $this->assertSame('completed', $call->status);
    }

    public function test_unknown_pricing_never_invents_cost_and_snapshots_do_not_change_with_configuration(): void
    {
        config(['ai_usage.budget.daily_cost' => null, 'ai_usage.budget.monthly_cost' => null]);
        $request = Content::factory()->create()->user->aiRequests()->create([]);
        $client = new ClientFake([CreateResponse::fake(), CreateResponse::fake()]);
        app(TrackedOpenAI::class)->chat($client, $request, 'generation', ['model' => 'unpriced-model', 'max_completion_tokens' => 50]);
        $this->assertNull(ProviderCall::sole()->estimated_cost);
        $this->assertNull(ProviderCall::sole()->reserved_cost);
        $this->assertNotNull(ProviderCall::sole()->total_tokens);
        app(TrackedOpenAI::class)->chat($client, $request, 'input_moderation', ['model' => 'gpt-4o-mini', 'max_completion_tokens' => 50]);
        $call = ProviderCall::latest('id')->firstOrFail();
        $cost = $call->estimated_cost;
        config(['ai_usage.pricing.gpt-4o-mini' => ['input' => 999, 'output' => 999]]);
        $this->assertSame($cost, $call->refresh()->estimated_cost);
        $this->assertSame('0.150000', $call->input_price_per_million);
        $pricing = app(AiPricing::class);
        config(['ai_usage.pricing.incomplete' => ['input' => 1]]);
        $this->assertNull($pricing->rates('incomplete'));
        $this->assertNull($pricing->rates('unknown'));
        $this->assertSame($pricing->rates('gpt-4o-mini'), $pricing->rates('gpt-4o-mini-2024-07-18'));
    }

    public function test_provider_exception_is_a_failed_call_without_fake_usage_or_raw_exception_storage(): void
    {
        $content = Content::factory()->create(['generated_content' => 'Saved good body']);
        $before = $content->refresh()->getAttributes();
        $client = new ClientFake([new RuntimeException('Secret provider details')]);
        $this->app->instance(ClientContract::class, $client);
        $response = $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/regenerate")->assertStatus(503);
        $this->assertStringNotContainsString('Secret', $response->getContent());
        $call = ProviderCall::sole();
        $this->assertSame('failed', $call->status);
        $this->assertNull($call->input_tokens);
        $this->assertNull($call->output_tokens);
        $this->assertNull($call->total_tokens);
        $this->assertNull($call->estimated_cost);
        $this->assertNotNull($call->reserved_cost);
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertSame('failed', AIRequest::sole()->status);
        $client->chat()->assertSent(1);
    }

    public function test_paid_call_accounting_survives_article_persistence_failure(): void
    {
        $content = Content::factory()->create(['generated_content' => 'Saved body', 'generated_meta_title' => 'Saved meta']);
        $before = $content->refresh()->getAttributes();
        DB::unprepared("CREATE TRIGGER reject_article_update BEFORE UPDATE ON contents BEGIN SELECT RAISE(FAIL, 'Simulated storage failure'); END");
        $client = new ClientFake([$this->decision(100, 20), SeoArticleResponse::fake(), $this->decision(200, 30)]);
        $this->app->instance(ClientContract::class, $client);
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/regenerate")->assertStatus(500)
            ->assertExactJson(['error' => 'Unable to save generated content']);
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertSame('failed', AIRequest::sole()->status);
        $this->assertDatabaseCount('provider_calls', 3);
        $this->assertSame(['completed'], ProviderCall::distinct()->pluck('status')->all());
        $this->assertGreaterThan(0, ProviderCall::sum('total_tokens'));
        $this->assertGreaterThan(0, ProviderCall::sum('estimated_cost'));
        $client->chat()->assertSent(3);
    }

    public function test_post_call_accounting_failure_stops_the_pipeline_and_retains_its_reservation(): void
    {
        $content = Content::factory()->create(['generated_content' => 'Saved body']);
        $before = $content->refresh()->getAttributes();
        $client = new ClientFake([$this->decision(100, 20)]);
        $this->app->instance(ClientContract::class, $client);
        ProviderCall::updating(fn () => throw new RuntimeException('Private accounting storage error'));
        try {
            $response = $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/regenerate")
                ->assertStatus(503)->assertJsonPath('code', 'ai_accounting_unavailable');
            $this->assertStringNotContainsString('Private', $response->getContent());
            $this->assertSame($before, $content->refresh()->getAttributes());
            $this->assertSame('failed', AIRequest::sole()->status);
            $call = ProviderCall::sole();
            $this->assertSame('pending', $call->status);
            $this->assertNull($call->total_tokens);
            $this->assertNull($call->estimated_cost);
            $this->assertNotNull($call->reserved_cost);
            $client->chat()->assertSent(1);
        } finally {
            ProviderCall::flushEventListeners();
        }
    }
}
