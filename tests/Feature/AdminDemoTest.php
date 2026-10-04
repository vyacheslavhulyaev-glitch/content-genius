<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use Database\Seeders\AdminDemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use OpenAI\Contracts\ClientContract;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin_demo.email' => 'admin-demo@contentgenius.hideas.dev', 'admin_demo.password' => 'test-only-recruiter-password']);
    }

    public function test_dedicated_seeder_is_idempotent_and_updates_only_recruiter_credentials(): void
    {
        $admin = User::factory()->admin()->create()->refresh();
        $normalDemo = User::factory()->create(['is_demo' => true])->refresh();
        $before = [$admin->getAttributes(), $normalDemo->getAttributes()];
        $this->seed(AdminDemoUserSeeder::class);
        config(['admin_demo.password' => 'updated-test-only-password']);
        $this->seed(AdminDemoUserSeeder::class);
        $demo = User::where('email', config('admin_demo.email'))->sole();
        $this->assertTrue($demo->is_admin_demo);
        $this->assertFalse($demo->is_admin);
        $this->assertFalse($demo->is_demo);
        $this->assertTrue(Hash::check(config('admin_demo.password'), $demo->password));
        $this->assertSame($before, [$admin->refresh()->getAttributes(), $normalDemo->refresh()->getAttributes()]);
        $this->assertDatabaseCount('users', 3);
        $this->postJson('/login', ['email' => $demo->email, 'password' => config('admin_demo.password')])->assertNoContent();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('is_admin_demo', true)->assertJsonPath('is_admin', false);
        $this->getJson('/api/admin/dashboard')->assertOk();
        $this->postJson('/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    #[DataProvider('collisionRoles')]
    public function test_seeder_refuses_unrelated_existing_identities(array $flags): void
    {
        $existing = User::factory()->create(['email' => strtoupper(config('admin_demo.email')), ...$flags])->refresh();
        // Also check conflicting flags inserted outside Eloquent's role normalization.
        if ($flags !== []) {
            DB::table('users')->where('id', $existing->id)->update($flags);
        }
        $before = $existing->refresh()->getAttributes();
        try {
            $this->seed(AdminDemoUserSeeder::class);
            $this->fail('An unrelated identity must never be converted to a recruiter demo.');
        } catch (LogicException) {
            $this->assertSame($before, $existing->refresh()->getAttributes());
            $this->assertDatabaseCount('users', 1);
        }
    }

    public static function collisionRoles(): array
    {
        return ['normal' => [[]], 'admin' => [['is_admin' => true]], 'normal demo' => [['is_demo' => true]],
            'conflicting admin' => [['is_admin_demo' => true, 'is_admin' => true]],
            'conflicting demo' => [['is_admin_demo' => true, 'is_demo' => true]]];
    }

    public function test_seeder_requires_credentials_and_separate_demo_identity(): void
    {
        foreach ([['admin_demo.password' => ''], ['admin_demo.password' => 'test', 'admin_demo.email' => config('demo.email')]] as $settings) {
            config($settings);
            try {
                $this->seed(AdminDemoUserSeeder::class);
                $this->fail('Invalid recruiter configuration must be refused.');
            } catch (LogicException) {
                $this->assertDatabaseCount('users', 0);
            }
        }
    }

    public function test_recruiter_flag_is_guarded_and_never_grants_real_admin_status(): void
    {
        $user = User::factory()->create()->refresh();
        $this->assertFalse($user->is_admin_demo);
        $this->assertFalse($user->isFillable('is_admin_demo'));
        $user->forceFill(['is_admin_demo' => true, 'is_admin' => true])->save();
        $this->assertFalse($user->refresh()->is_admin);
    }

    #[DataProvider('mutations')]
    public function test_recruiter_cannot_mutate_content_or_even_resolve_the_provider_client(string $method, string $path): void
    {
        $demo = User::factory()->create(['is_admin_demo' => true]);
        $content = Content::factory()->for($demo)->create(['generated_content' => 'Keep previous result'])->refresh();
        $before = $content->getAttributes();
        $this->app->bind(ClientContract::class, fn () => $this->fail('Read-only requests must not resolve an OpenAI client.'));
        $this->actingAs($demo, 'web')->json($method, str_replace('{id}', (string) $content->id, $path), [])
            ->assertForbidden()->assertJsonPath('message', 'The recruiter admin demo is read-only.');
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertDatabaseCount('contents', 1);
        $this->assertDatabaseCount('content_groups', 1);
        $this->assertDatabaseCount('ai_requests', 0);
        $this->assertDatabaseCount('provider_calls', 0);
    }

    public static function mutations(): array
    {
        return ['create' => ['POST', '/api/contents'], 'edit' => ['PATCH', '/api/contents/{id}'],
            'delete' => ['DELETE', '/api/contents/{id}'], 'translate' => ['POST', '/api/contents/{id}/translations'],
            'generate' => ['POST', '/api/contents/{id}/generate'], 'regenerate' => ['POST', '/api/contents/{id}/regenerate']];
    }

    public function test_public_dashboard_removes_identifying_values_while_real_admin_keeps_details(): void
    {
        $owner = User::factory()->create(['name' => 'Private owner', 'email' => 'private@example.test']);
        $content = Content::factory()->for($owner)->create(['title' => 'Private title', 'topic' => 'Private topic', 'generated_content' => 'Private article']);
        $request = $owner->aiRequests()->create(['content_id' => $content->id, 'status' => 'failed', 'tokens_used' => 123, 'error_message' => 'Private error']);
        $demo = User::factory()->create(['is_admin_demo' => true]);
        $response = $this->actingAs($demo, 'web')->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('recent_ai_requests.0.user.name', 'User #'.$owner->id)
            ->assertJsonMissingPath('recent_ai_requests.0.user.email')
            ->assertJsonPath('recent_ai_requests.0.content.title', 'Content #'.$content->id)
            ->assertJsonPath('recent_ai_requests.0.id', $request->id)
            ->assertJsonPath('recent_ai_requests.0.tokens_used', 123)
            ->assertJsonPath('recent_ai_requests.0.status', 'failed');
        foreach (['Private owner', 'private@example.test', 'Private title', 'Private topic', 'Private article', 'Private error'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        Auth::forgetGuards();
        $this->actingAs(User::factory()->admin()->create(), 'web')->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('recent_ai_requests.0.user.email', $owner->email)
            ->assertJsonPath('recent_ai_requests.0.user.name', $owner->name)
            ->assertJsonPath('recent_ai_requests.0.content.title', $content->title);
        Auth::forgetGuards();
        $this->actingAs(User::factory()->create(['is_demo' => true]), 'web')->getJson('/api/admin/dashboard')->assertForbidden();
    }
}
