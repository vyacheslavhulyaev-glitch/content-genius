<?php

namespace Tests\Feature;

use App\Exceptions\AiBudgetExceeded;
use App\Models\AIRequest;
use App\Models\Content;
use App\Models\ProviderCall;
use App\Services\ContentModerator;
use App\Services\GlobalAiBudget;
use App\Services\TrackedOpenAI;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\ResponseContract;
use OpenAI\Contracts\ResponseStreamContract;
use OpenAI\Responses\Chat\CreateResponse;
use OpenAI\Testing\ClientFake;
use OpenAI\Testing\Requests\TestRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\SeoArticleResponse;
use Tests\TestCase;

class GlobalAiBudgetTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.openai.model' => 'gpt-4o-mini', 'moderation.model' => 'gpt-4o-mini']);
    }

    private function fake(array $responses = []): ClientFake
    {
        $client = new ClientFake($responses);
        $this->app->instance(ClientContract::class, $client);

        return $client;
    }

    private function decision(): CreateResponse
    {
        return CreateResponse::fake(['choices' => [['message' => ['content' => json_encode(array_fill_keys(ContentModerator::CATEGORIES, false))]]]]);
    }

    private function historicalCall(array $fields = []): ProviderCall
    {
        return ProviderCall::create(array_replace([
            'provider' => 'openai', 'model' => 'gpt-4o-mini', 'operation' => 'input_moderation', 'status' => 'completed',
            'input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150, 'estimated_cost' => '0.01',
            'reserved_tokens' => 10000, 'reserved_cost' => 1, 'currency' => 'USD',
            'budget_date' => now('UTC')->toDateString(), 'started_at' => now('UTC'), 'finished_at' => now('UTC'),
        ], $fields));
    }

    public static function ceilings(): array
    {
        return [['daily_calls'], ['daily_tokens'], ['daily_cost'], ['monthly_cost']];
    }

    #[DataProvider('ceilings')]
    public function test_zero_ceiling_blocks_before_any_paid_call_and_preserves_regeneration(string $ceiling): void
    {
        $this->freezeTime();
        config(['ai_usage.budget.'.$ceiling => 0, 'app.debug' => true]);
        $content = Content::factory()->create(['generated_content' => 'Saved good body',
            'generated_meta_title' => 'Saved meta', 'generated_meta_description' => 'Saved description']);
        $before = $content->refresh()->getAttributes();
        $client = $this->fake();
        $monthly = $ceiling === 'monthly_cost';
        $reset = $monthly ? now('UTC')->startOfMonth()->addMonth() : now('UTC')->addDay()->startOfDay();
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/regenerate")->assertStatus(429)
            ->assertJsonPath('code', 'ai_global_budget_exceeded')->assertJsonPath('error', $monthly ? 'Monthly AI budget reached' : 'Daily AI budget reached')
            ->assertJsonPath('reset_at', $reset->toIso8601ZuluString())
            ->assertHeader('Retry-After', (string) ($reset->timestamp - now('UTC')->timestamp));
        $client->assertNothingSent();
        $this->assertDatabaseCount('provider_calls', 0);
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertSame('failed', AIRequest::sole()->status);
    }

    public static function blockedStages(): array
    {
        return ['generation' => [1], 'output moderation' => [2]];
    }

    #[DataProvider('blockedStages')]
    public function test_every_stage_checks_the_shared_budget_without_creating_a_rejected_call(int $limit): void
    {
        config(['ai_usage.budget.daily_calls' => $limit]);
        $content = Content::factory()->create(['generated_content' => 'Saved article', 'generated_meta_title' => 'Saved meta']);
        $before = $content->refresh()->getAttributes();
        $client = $this->fake([$this->decision(), SeoArticleResponse::fake(), $this->decision()]);
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/regenerate")->assertStatus(429)
            ->assertJsonPath('code', 'ai_global_budget_exceeded');
        $client->chat()->assertSent($limit);
        $this->assertDatabaseCount('provider_calls', $limit);
        $this->assertSame(['completed'], ProviderCall::distinct()->pluck('status')->all());
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertSame('failed', AIRequest::sole()->status);
    }

    public function test_global_call_ceiling_is_shared_across_users_and_resets_at_utc_midnight(): void
    {
        $this->travelTo(now('UTC')->setTime(23, 59, 59));
        config(['ai_usage.budget.daily_calls' => 1]);
        $this->historicalCall();
        $content = Content::factory()->create();
        $client = $this->fake();
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertStatus(429)
            ->assertJsonPath('retry_after', 1);
        $client->assertNothingSent();
        $this->travel(1)->seconds();
        // Input moderation succeeds in the new day; generation then hits the shared one-call ceiling.
        $client = $this->fake([$this->decision()]);
        $this->postJson("/api/contents/{$content->id}/generate")->assertStatus(429);
        $client->chat()->assertSent(1);
        $this->assertDatabaseCount('provider_calls', 2);
    }

    public function test_token_and_cost_ceilings_count_actual_usage_and_never_add_reservations_twice(): void
    {
        $this->historicalCall();
        config(['ai_usage.budget.daily_tokens' => 150]);
        $content = Content::factory()->create();
        $client = $this->fake();
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertStatus(429);
        config(['ai_usage.budget.daily_tokens' => 100000, 'ai_usage.budget.daily_cost' => 0.01]);
        $this->postJson("/api/contents/{$content->id}/generate")->assertStatus(429);
        $client->assertNothingSent();
        config(['ai_usage.budget.daily_cost' => 0.5, 'ai_usage.budget.daily_calls' => 2]);
        $client = $this->fake([$this->decision()]);
        $this->postJson("/api/contents/{$content->id}/generate")->assertStatus(429);
        $client->chat()->assertSent(1);
        $this->assertDatabaseCount('provider_calls', 2);
    }

    public function test_pending_reservation_blocks_another_user_before_first_provider_call_finishes(): void
    {
        config(['ai_usage.budget.daily_calls' => 1]);
        $first = Content::factory()->create();
        $second = Content::factory()->create();
        $check = function () use ($second): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame('pending', ProviderCall::sole()->status);
            $this->app['auth']->forgetGuards();
            $this->actingAs($second->user, 'web')->postJson("/api/contents/{$second->id}/generate")->assertStatus(429);
            $this->assertDatabaseCount('provider_calls', 1);
        };
        $client = new class($check, $this->decision()) extends ClientFake
        {
            public function __construct(private \Closure $check, CreateResponse $response)
            {
                parent::__construct([$response]);
            }

            public function record(TestRequest $request): ResponseContract|ResponseStreamContract|string
            {
                ($this->check)();

                return parent::record($request);
            }
        };
        $this->app->instance(ClientContract::class, $client);
        $this->actingAs($first->user, 'web')->postJson("/api/contents/{$first->id}/generate")->assertStatus(429);
        $client->chat()->assertSent(1);
        $this->assertDatabaseCount('provider_calls', 1);
    }

    public function test_missing_usage_keeps_a_conservative_reservation_for_budget_only(): void
    {
        config(['ai_usage.budget.daily_tokens' => 10000]);
        $this->historicalCall(['input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null,
            'estimated_cost' => null, 'reserved_tokens' => 10000, 'status' => 'failed']);
        $content = Content::factory()->create();
        $client = $this->fake();
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertStatus(429);
        config(['ai_usage.budget.daily_tokens' => null, 'ai_usage.budget.daily_cost' => 1]);
        $this->postJson("/api/contents/{$content->id}/generate")->assertStatus(429);
        $client->assertNothingSent();
        $this->assertNull(ProviderCall::sole()->estimated_cost);
        $this->assertNull(ProviderCall::sole()->total_tokens);
    }

    public function test_missing_pricing_and_invalid_budget_configuration_fail_closed_without_provider_records(): void
    {
        $content = Content::factory()->create();
        $client = $this->fake();
        config(['moderation.model' => 'unknown-model']);
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertStatus(503)
            ->assertJsonPath('code', 'ai_accounting_unavailable');
        config(['moderation.model' => 'gpt-4o-mini', 'ai_usage.budget.daily_calls' => 'invalid']);
        $this->postJson("/api/contents/{$content->id}/generate")->assertStatus(503)->assertJsonPath('code', 'ai_accounting_unavailable');
        $client->assertNothingSent();
        $this->assertDatabaseCount('provider_calls', 0);
    }

    public function test_accounting_write_failure_stops_before_any_provider_call(): void
    {
        DB::table('ai_budget_locks')->delete();
        $content = Content::factory()->create();
        $client = $this->fake();
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertStatus(503)
            ->assertJsonPath('code', 'ai_accounting_unavailable');
        $client->assertNothingSent();
        $this->assertDatabaseCount('provider_calls', 0);
    }

    private function monthlyParameters(): array
    {
        // A deterministic $0.01 reservation isolates monetary boundary checks from token limits.
        config(['ai_usage.pricing.gpt-4o-mini' => ['input' => 0, 'output' => 1]]);

        return ['model' => 'gpt-4o-mini', 'max_completion_tokens' => 10000];
    }

    public function test_exact_monthly_boundary_allows_one_reservation_and_rejects_the_next(): void
    {
        $parameters = $this->monthlyParameters();
        $this->historicalCall(['estimated_cost' => '0.99', 'budget_date' => now('UTC')->startOfMonth()->toDateString()]);
        config(['ai_usage.budget.daily_cost' => null]);
        $request = Content::factory()->create()->user->aiRequests()->create([]);
        $call = app(GlobalAiBudget::class)->reserve($request, 'generation', $parameters);
        $this->assertSame('0.01000000', $call->reserved_cost);
        $this->assertNull($call->estimated_cost);
        try {
            app(GlobalAiBudget::class)->reserve($request, 'generation', $parameters);
            $this->fail('A second reservation must exceed the monthly ceiling.');
        } catch (AiBudgetExceeded $exception) {
            $this->assertSame('Monthly AI budget reached', $exception->render()->getData()->error);
        }
        $this->assertDatabaseCount('provider_calls', 2);
    }

    public function test_calls_below_monthly_ceiling_replace_reservations_with_actual_cost_once(): void
    {
        $parameters = $this->monthlyParameters();
        $this->historicalCall(['estimated_cost' => '0.98', 'reserved_cost' => 1,
            'budget_date' => now('UTC')->startOfMonth()->toDateString()]);
        config(['ai_usage.budget.daily_cost' => null]);
        $request = Content::factory()->create()->user->aiRequests()->create([]);
        $response = CreateResponse::fake(['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 1000, 'total_tokens' => 1100]]);
        $client = new ClientFake([$response, $response]);
        for ($i = 0; $i < 2; $i++) {
            app(TrackedOpenAI::class)->chat($client, $request, 'generation', $parameters);
        }
        $client->chat()->assertSent(2);
        $calls = $request->providerCalls()->get();
        $this->assertSame(['0.01000000', '0.01000000'], $calls->pluck('reserved_cost')->all());
        $this->assertSame(['0.00100000', '0.00100000'], $calls->pluck('estimated_cost')->all());
        $this->assertEqualsWithDelta(0.982, ProviderCall::sum('estimated_cost'), 0.000000001);
    }

    public function test_one_ledger_unit_above_monthly_boundary_is_rejected_without_a_provider_call(): void
    {
        $parameters = $this->monthlyParameters();
        $this->historicalCall(['estimated_cost' => '0.99000001']);
        config(['ai_usage.budget.daily_cost' => null]);
        $request = Content::factory()->create()->user->aiRequests()->create([]);
        $client = new ClientFake;
        try {
            app(TrackedOpenAI::class)->chat($client, $request, 'generation', $parameters);
            $this->fail('The smallest accounted cost excess must be rejected.');
        } catch (AiBudgetExceeded) {
            $client->assertNothingSent();
            $this->assertDatabaseCount('provider_calls', 1);
        }
    }

    public static function invalidMonthlyLimits(): array
    {
        return [['invalid'], [-1], [INF], [NAN]];
    }

    #[DataProvider('invalidMonthlyLimits')]
    public function test_invalid_monthly_configuration_fails_closed(mixed $limit): void
    {
        config(['ai_usage.budget.monthly_cost' => $limit]);
        $content = Content::factory()->create();
        $client = $this->fake();
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertStatus(503)
            ->assertJsonPath('code', 'ai_accounting_unavailable');
        $client->assertNothingSent();
        $this->assertDatabaseCount('provider_calls', 0);
    }

    public static function monthlyBlockedStages(): array
    {
        return ['input moderation' => ['1.00', 0], 'generation' => ['0.99', 1], 'output moderation' => ['0.989', 2]];
    }

    #[DataProvider('monthlyBlockedStages')]
    public function test_monthly_rejection_at_every_paid_stage_preserves_content_and_creates_no_fake_usage(string $used, int $sent): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
        $this->monthlyParameters();
        config(['ai_usage.moderation_max_output_tokens' => 10000, 'generation.max_output_tokens' => 10000]);
        $this->historicalCall(['estimated_cost' => $used, 'budget_date' => '2026-10-01']);
        $content = Content::factory()->create(['generated_content' => 'Saved body', 'generated_title' => 'Saved title',
            'generated_meta_title' => 'Saved meta', 'generated_meta_description' => 'Saved description']);
        $before = $content->refresh()->getAttributes();
        $usage = ['prompt_tokens' => 100, 'completion_tokens' => 1000, 'total_tokens' => 1100];
        $decision = CreateResponse::fake(['choices' => [['message' => ['content' => json_encode(array_fill_keys(ContentModerator::CATEGORIES, false))]]], 'usage' => $usage]);
        $client = $this->fake([$decision, SeoArticleResponse::fake(['usage' => $usage]), $decision]);
        $reset = CarbonImmutable::parse('2026-11-01', 'UTC');
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/regenerate")->assertStatus(429)
            ->assertJsonPath('code', 'ai_global_budget_exceeded')->assertJsonPath('error', 'Monthly AI budget reached')
            ->assertJsonPath('reset_at', $reset->toIso8601ZuluString())
            ->assertHeader('Retry-After', (string) ($reset->timestamp - now('UTC')->timestamp));
        $client->chat()->assertSent($sent);
        $this->assertDatabaseCount('provider_calls', 1 + $sent);
        $this->assertSame(['completed'], ProviderCall::distinct()->pluck('status')->all());
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertSame('failed', AIRequest::sole()->status);
        $this->assertEqualsWithDelta((float) $used + $sent * 0.001, ProviderCall::sum('estimated_cost'), 0.000000001);
    }

    public function test_daily_cost_and_monthly_cost_both_apply_and_monthly_rejection_takes_precedence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'));
        $historical = $this->historicalCall(['estimated_cost' => '1.00', 'budget_date' => '2026-10-01']);
        $today = $this->historicalCall(['estimated_cost' => '0.10']);
        $content = Content::factory()->create();
        $client = $this->fake();
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertStatus(429)
            ->assertJsonPath('error', 'Monthly AI budget reached')->assertJsonPath('reset_at', '2026-11-01T00:00:00Z');
        $historical->update(['estimated_cost' => '0.50']);
        $this->postJson("/api/contents/{$content->id}/generate")->assertStatus(429)
            ->assertJsonPath('error', 'Daily AI budget reached')->assertJsonPath('reset_at', '2026-10-21T00:00:00Z');
        $client->assertNothingSent();
        $today->update(['estimated_cost' => '0.01']);
        $client = $this->fake([$this->decision(), SeoArticleResponse::fake(), $this->decision()]);
        $this->postJson("/api/contents/{$content->id}/generate")->assertOk();
        $client->chat()->assertSent(3);
    }

    public function test_monthly_budget_resets_at_utc_month_boundary_including_unknown_reservations(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 23:59:59', 'UTC'));
        config(['app.timezone' => 'Pacific/Auckland']);
        $this->assertSame(11, now('Pacific/Auckland')->month);
        $this->historicalCall(['estimated_cost' => '0.90', 'budget_date' => '2026-10-01']);
        $this->historicalCall(['estimated_cost' => null, 'total_tokens' => null, 'reserved_cost' => '0.10',
            'status' => 'pending', 'budget_date' => '2026-10-02']);
        $content = Content::factory()->create();
        $client = $this->fake();
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertStatus(429)
            ->assertJsonPath('retry_after', 1)->assertJsonPath('reset_at', '2026-11-01T00:00:00Z')->assertHeader('Retry-After', '1');
        $client->assertNothingSent();
        $this->travel(1)->seconds();
        $client = $this->fake([$this->decision(), SeoArticleResponse::fake(), $this->decision()]);
        $this->postJson("/api/contents/{$content->id}/generate")->assertOk();
        $client->chat()->assertSent(3);
        $this->assertSame(3, ProviderCall::where('budget_date', '2026-11-01')->count());
    }

    public static function unresolvedMonthlyCosts(): array
    {
        return ['pending' => ['pending'], 'provider failure' => ['failed'], 'missing usage' => ['completed']];
    }

    #[DataProvider('unresolvedMonthlyCosts')]
    public function test_monthly_budget_retains_unknown_cost_reservations_from_previous_days(string $status): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20', 'UTC'));
        $this->historicalCall(['estimated_cost' => null, 'total_tokens' => null, 'reserved_cost' => '1.00',
            'status' => $status, 'budget_date' => '2026-10-01']);
        $content = Content::factory()->create();
        $client = $this->fake();
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertStatus(429)
            ->assertJsonPath('error', 'Monthly AI budget reached');
        $client->assertNothingSent();
        $this->assertDatabaseCount('provider_calls', 1);
        $this->assertNull(ProviderCall::sole()->estimated_cost);
        $this->assertNull(ProviderCall::sole()->total_tokens);
    }

    public function test_monthly_cost_ceiling_fails_closed_with_unknown_pricing_or_unpriced_month_history(): void
    {
        config(['ai_usage.budget.daily_cost' => null, 'moderation.model' => 'unknown-model']);
        $content = Content::factory()->create();
        $client = $this->fake();
        $this->actingAs($content->user, 'web')->postJson("/api/contents/{$content->id}/generate")->assertStatus(503)
            ->assertJsonPath('code', 'ai_accounting_unavailable');
        $this->assertDatabaseCount('provider_calls', 0);
        config(['moderation.model' => 'gpt-4o-mini']);
        $this->historicalCall(['estimated_cost' => null, 'reserved_cost' => null,
            'budget_date' => now('UTC')->startOfMonth()->toDateString()]);
        $this->postJson("/api/contents/{$content->id}/generate")->assertStatus(503)->assertJsonPath('code', 'ai_accounting_unavailable');
        $client->assertNothingSent();
        $this->assertDatabaseCount('provider_calls', 1);
    }

    public function test_two_concurrent_database_reservations_cannot_overspend_monthly_limit(): void
    {
        $this->historicalCall(['estimated_cost' => '0.99', 'budget_date' => now('UTC')->startOfMonth()->toDateString()]);
        $requests = [];
        for ($i = 0; $i < 2; $i++) {
            $requests[] = Content::factory()->create()->user->aiRequests()->create([]);
        }
        $database = tempnam(sys_get_temp_dir(), 'ai-budget-');
        $gate = $database.'.gate';
        $processes = [];
        try {
            // Copy the isolated test database; workers never touch the application's database.
            unlink($database);
            $pdo = DB::connection()->getPdo();
            $pdo->exec('VACUUM INTO '.$pdo->quote($database));
            foreach ($requests as $request) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/reserveMonthlyBudget.php'), $database, $gate, (string) $request->id], base_path(), ['APP_ENV' => 'testing']);
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            foreach ($processes as $process) {
                while (! str_contains($process->getOutput(), 'READY') && $process->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('READY', $process->getOutput(), $process->getErrorOutput());
            }
            touch($gate);
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $results[] = trim(str_replace('READY', '', $process->getOutput()));
            }
            sort($results);
            $this->assertSame(['monthly_rejected', 'reserved'], $results);
            $snapshot = new \PDO('sqlite:'.$database);
            $this->assertSame(2, (int) $snapshot->query('SELECT COUNT(*) FROM provider_calls')->fetchColumn());
            $this->assertSame(1, (int) $snapshot->query("SELECT COUNT(*) FROM provider_calls WHERE status = 'pending'")->fetchColumn());
            $this->assertEqualsWithDelta(1.00, (float) $snapshot->query('SELECT SUM(COALESCE(estimated_cost, reserved_cost)) FROM provider_calls')->fetchColumn(), 0.000000001);
            $snapshot = null;
        } finally {
            $snapshot = null;
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ([$gate, $database, $database.'-journal', $database.'-wal', $database.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
