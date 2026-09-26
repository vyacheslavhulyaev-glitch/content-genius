<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use OpenAI\Contracts\ClientContract;
use OpenAI\Testing\ClientFake;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_default_to_non_admin_and_cannot_mass_assign_admin_status(): void
    {
        $user = User::factory()->create()->refresh();

        $this->assertFalse($user->is_admin);
        $this->assertFalse($user->isFillable('is_admin'));
        $this->assertSame(0, (int) DB::table('users')->where('id', $user->id)->value('is_admin'));
        $this->assertTrue(User::factory()->admin()->create()->refresh()->is_admin);
    }

    #[DataProvider('adminStatuses')]
    public function test_current_user_exposes_boolean_admin_status(bool $isAdmin): void
    {
        $user = User::factory()->create(['is_admin' => $isAdmin])->refresh();
        $this->actingAs($user, 'web')->getJson('/api/user')->assertOk()
            ->assertJsonPath('is_admin', $isAdmin)
            ->assertJsonMissingPath('password')->assertJsonMissingPath('remember_token');
    }

    public function test_guests_cannot_access_the_dashboard(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    public function test_normal_users_cannot_access_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/admin/dashboard')
            ->assertForbidden();
    }

    public function test_revoked_admins_cannot_access_the_dashboard(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'web')->getJson('/api/admin/dashboard')->assertOk();
        DB::table('users')->where('id', $admin->id)->update(['is_admin' => false]);
        Auth::forgetGuards();

        $this->actingAs($admin->fresh(), 'web')->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public function test_admin_receives_zero_counts_when_there_is_no_content_or_request(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web')->getJson('/api/admin/dashboard')
            ->assertOk()->assertExactJson([
                'summary' => [
                    'total_users' => 1,
                    'total_contents' => 0,
                    'generated_contents' => 0,
                    'total_ai_requests' => 0,
                    'completed_ai_requests' => 0,
                    'failed_ai_requests' => 0,
                    'pending_ai_requests' => 0,
                    'total_tokens_used' => 0,
                ],
                'recent_ai_requests' => [],
            ]);
    }

    public function test_summary_covers_all_users_and_only_approved_fields_are_exposed(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $generated = $user->contents()->create([
            'title' => 'Generated', 'topic' => 'Private topic', 'generated_content' => 'Private result',
            'metadata' => ['secret' => 'Private metadata'],
        ]);
        $admin->contents()->create(['title' => 'Empty generated', 'topic' => 'Topic', 'generated_content' => '']);
        $draft = $admin->contents()->create(['title' => 'Draft', 'topic' => 'Topic']);
        $completed = $user->aiRequests()->create([
            'content_id' => $generated->id, 'status' => 'completed', 'tokens_used' => 30, 'cost' => 1,
        ]);
        $failed = $admin->aiRequests()->create([
            'content_id' => $draft->id, 'status' => 'failed', 'tokens_used' => 7,
            'error_message' => 'Private error',
        ]);
        $pending = $user->aiRequests()->create(['status' => 'pending']);

        $this->actingAs($admin, 'web')->getJson('/api/admin/dashboard')->assertOk()->assertExactJson([
            'summary' => [
                'total_users' => 2,
                'total_contents' => 3,
                'generated_contents' => 2,
                'total_ai_requests' => 3,
                'completed_ai_requests' => 1,
                'failed_ai_requests' => 1,
                'pending_ai_requests' => 1,
                'total_tokens_used' => 37,
            ],
            'recent_ai_requests' => [
                [
                    'id' => $pending->id,
                    'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                    'content' => null, 'status' => 'pending', 'tokens_used' => null,
                    'created_at' => $pending->created_at->toJSON(),
                ],
                [
                    'id' => $failed->id,
                    'user' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email],
                    'content' => ['id' => $draft->id, 'title' => $draft->title],
                    'status' => 'failed', 'tokens_used' => 7,
                    'created_at' => $failed->created_at->toJSON(),
                ],
                [
                    'id' => $completed->id,
                    'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                    'content' => ['id' => $generated->id, 'title' => $generated->title],
                    'status' => 'completed', 'tokens_used' => 30,
                    'created_at' => $completed->created_at->toJSON(),
                ],
            ],
        ]);
    }

    public function test_recent_requests_are_limited_and_ordered_by_timestamp_then_id(): void
    {
        $admin = User::factory()->admin()->create();
        $newest = $admin->aiRequests()->create([]);
        $newest->forceFill(['created_at' => '2026-09-26 12:00:00'])->save();
        $ids = [];
        for ($index = 0; $index < 21; $index++) {
            $request = $admin->aiRequests()->create([]);
            $request->forceFill(['created_at' => '2026-09-25 12:00:00'])->save();
            $ids[] = $request->id;
        }

        $response = $this->actingAs($admin, 'web')->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonCount(20, 'recent_ai_requests')
            ->assertJsonPath('summary.total_ai_requests', 22)
            ->assertJsonPath('summary.total_tokens_used', 0);

        $this->assertSame(
            [$newest->id, ...array_slice(array_reverse($ids), 0, 19)],
            array_column($response->json('recent_ai_requests'), 'id'),
        );
    }

    public function test_admin_cannot_access_another_users_content_through_normal_endpoints(): void
    {
        $admin = User::factory()->admin()->create();
        $content = User::factory()->create()->contents()->create(['title' => 'Private', 'topic' => 'Topic']);
        $client = new ClientFake([]);
        $this->app->instance(ClientContract::class, $client);

        $this->actingAs($admin, 'web')->getJson('/api/contents')->assertOk()->assertExactJson([]);
        $this->postJson("/api/contents/{$content->id}/generate")->assertNotFound();

        $client->assertNothingSent();
        $this->assertDatabaseCount('ai_requests', 0);
    }

    #[DataProvider('adminStatuses')]
    public function test_admin_and_forbidden_responses_allow_spa_credentials(bool $isAdmin): void
    {
        $user = User::factory()->create(['is_admin' => $isAdmin]);
        $this->actingAs($user, 'web')->getJson('/api/admin/dashboard', ['Origin' => 'http://localhost:5173'])
            ->assertStatus($isAdmin ? 200 : 403)
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public static function adminStatuses(): array
    {
        return ['normal user' => [false], 'admin' => [true]];
    }

    public function test_obsolete_debug_endpoint_is_unavailable_and_does_not_call_openai(): void
    {
        $client = new ClientFake([]);
        $this->app->instance(ClientContract::class, $client);

        $this->get('/ai')->assertNotFound();

        $client->assertNothingSent();
    }
}
