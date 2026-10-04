<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\ProviderCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiUsageAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function providerCall(string $date, string $operation, int $input, int $output, ?string $cost, string $status = 'completed'): ProviderCall
    {
        return ProviderCall::create([
            'provider' => 'openai', 'model' => 'gpt-4o-mini', 'operation' => $operation, 'status' => $status,
            'input_tokens' => $input, 'output_tokens' => $output, 'total_tokens' => $input + $output,
            'estimated_cost' => $cost, 'currency' => 'USD', 'reserved_tokens' => 99999, 'reserved_cost' => 999,
            'budget_date' => $date, 'started_at' => $date.' 12:00:00', 'finished_at' => $date.' 12:01:00',
        ]);
    }

    public function test_utc_periods_counts_costs_and_operations_use_provider_records_without_double_counting_legacy_totals(): void
    {
        $this->travelTo('2026-10-15 14:00:00');
        $admin = User::factory()->admin()->create();
        $completed = $admin->aiRequests()->create(['status' => 'completed', 'tokens_used' => 99999, 'cost' => 999]);
        $failed = $admin->aiRequests()->create(['status' => 'failed', 'tokens_used' => 88888, 'cost' => 888]);
        $pending = $admin->aiRequests()->create(['status' => 'pending']);
        $failed->forceFill(['created_at' => '2026-10-01 12:00:00'])->save();
        $pending->forceFill(['created_at' => '2026-09-30 12:00:00'])->save();
        $this->providerCall('2026-10-15', 'input_moderation', 100, 10, '0.01')->update(['ai_request_id' => $completed->id]);
        $this->providerCall('2026-10-15', 'generation', 200, 20, '0.02')->update(['ai_request_id' => $completed->id]);
        $this->providerCall('2026-10-15', 'output_moderation', 300, 30, '0.03')->update(['ai_request_id' => $completed->id]);
        $this->providerCall('2026-10-01', 'generation', 400, 40, '0.04');
        $this->providerCall('2026-09-30', 'generation', 500, 50, '0.05');
        $unknown = $this->providerCall('2026-10-15', 'input_moderation', 0, 0, null, 'failed');
        $unknown->update(['input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null]);
        $response = $this->actingAs($admin, 'web')->getJson('/api/admin/dashboard')->assertOk();
        $response->assertJsonPath('provider_usage.timezone', 'UTC')->assertJsonPath('provider_usage.currency', 'USD');
        foreach (['today' => [4, 600, 60, 660, '0.06000000'], 'month' => [5, 1000, 100, 1100, '0.10000000'],
            'all_time' => [6, 1500, 150, 1650, '0.15000000']] as $period => [$calls, $input, $output, $total, $cost]) {
            $prefix = 'provider_usage.periods.'.$period;
            $response->assertJsonPath($prefix.'.provider_calls', $calls)->assertJsonPath($prefix.'.input_tokens', $input)
                ->assertJsonPath($prefix.'.output_tokens', $output)->assertJsonPath($prefix.'.total_tokens', $total)
                ->assertJsonPath($prefix.'.estimated_cost', $cost)->assertJsonPath($prefix.'.unknown_cost_calls', 1)
                ->assertJsonPath($prefix.'.unknown_usage_calls', 1)->assertJsonPath($prefix.'.failed_provider_calls', 1)
                ->assertJsonPath($prefix.'.completed_provider_calls', $calls - 1);
        }
        $response->assertJsonPath('provider_usage.periods.today.logical_requests.total', 1)
            ->assertJsonPath('provider_usage.periods.month.logical_requests.total', 2)
            ->assertJsonPath('provider_usage.periods.all_time.logical_requests', ['total' => 3, 'completed' => 1, 'failed' => 1, 'pending' => 1])
            ->assertJsonPath('provider_usage.operations.generation.provider_calls', 3)
            ->assertJsonPath('provider_usage.operations.generation.total_tokens', 1210)
            ->assertJsonPath('provider_usage.operations.generation.estimated_cost', '0.11000000')
            ->assertJsonPath('provider_usage.operations.input_moderation.provider_calls', 2)
            ->assertJsonPath('provider_usage.operations.output_moderation.total_tokens', 330);
    }

    public function test_unknown_cost_is_null_and_pending_reservations_are_not_reported_as_real_usage(): void
    {
        $call = $this->providerCall(now('UTC')->toDateString(), 'generation', 0, 0, null, 'pending');
        $call->update(['input_tokens' => null, 'output_tokens' => null, 'total_tokens' => null, 'finished_at' => null]);
        $this->actingAs(User::factory()->admin()->create(), 'web')->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('provider_usage.periods.today.estimated_cost', null)
            ->assertJsonPath('provider_usage.periods.today.pending_provider_calls', 1)
            ->assertJsonPath('provider_usage.periods.today.total_tokens', 0)
            ->assertJsonPath('provider_usage.periods.today.unknown_usage_calls', 1)
            ->assertJsonPath('provider_usage.operations.input_moderation.estimated_cost', '0.00000000');
    }

    public function test_normal_and_demo_accounts_cannot_access_provider_analytics_even_if_demo_admin_flag_is_corrupt(): void
    {
        $normal = User::factory()->create();
        $this->actingAs($normal, 'web')->getJson('/api/admin/dashboard')->assertForbidden()->assertJsonMissingPath('provider_usage');
        $demo = User::factory()->create();
        $demo->forceFill(['is_demo' => true])->save();
        DB::table('users')->where('id', $demo->id)->update(['is_admin' => true]);
        $this->actingAs($demo->fresh(), 'web')->getJson('/api/admin/dashboard')->assertForbidden()->assertJsonMissingPath('provider_usage');
    }

    public function test_content_deletion_keeps_provider_accounting_and_historical_totals(): void
    {
        $content = Content::factory()->create();
        $request = $content->user->aiRequests()->create(['content_id' => $content->id, 'status' => 'completed']);
        $call = $this->providerCall(now('UTC')->toDateString(), 'generation', 10, 20, '0.01');
        $call->update(['ai_request_id' => $request->id, 'content_id' => $content->id]);
        $content->delete();
        $this->assertNull($call->refresh()->content_id);
        $this->assertSame($request->id, $call->ai_request_id);
        $request->delete();
        $this->assertNull($call->refresh()->ai_request_id);
        $this->actingAs(User::factory()->admin()->create(), 'web')->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('provider_usage.periods.all_time.total_tokens', 30)
            ->assertJsonPath('provider_usage.periods.all_time.estimated_cost', '0.01000000');
    }
}
