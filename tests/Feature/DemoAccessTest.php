<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class DemoAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_seeder_creates_an_idempotent_non_admin_demo_without_changing_other_users(): void
    {
        $admin = User::factory()->admin()->create()->refresh();
        $before = $admin->getAttributes();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $demo = User::where('email', config('demo.email'))->sole();
        $this->assertTrue($demo->is_demo);
        $this->assertFalse($demo->is_admin);
        $this->assertTrue(Hash::check(config('demo.password'), $demo->password));
        $this->assertSame($before, $admin->refresh()->getAttributes());
        $this->assertDatabaseCount('users', 2);
        $demo->forceFill(['is_admin' => true])->save();
        $this->assertFalse($demo->refresh()->is_admin);
        $this->assertFalse($demo->isFillable('is_demo'));
        $this->assertFalse($demo->isFillable('is_admin'));
    }

    public function test_demo_can_login_but_never_access_admin_even_with_a_corrupt_admin_flag(): void
    {
        $this->seed(DemoUserSeeder::class);
        $demo = User::where('email', config('demo.email'))->sole();
        $this->postJson('/login', ['email' => $demo->email, 'password' => config('demo.password')])->assertNoContent();
        $this->getJson('/api/user')->assertOk()->assertJsonPath('is_admin', false);
        $this->getJson('/api/admin/dashboard')->assertForbidden();
        DB::table('users')->where('id', $demo->id)->update(['is_admin' => true]);
        $this->actingAs($demo->fresh(), 'web')->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public function test_demo_seeder_refuses_to_reuse_an_existing_account(): void
    {
        $admin = User::factory()->admin()->create(['email' => config('demo.email')])->refresh();
        $before = $admin->getAttributes();
        try {
            $this->seed(DemoUserSeeder::class);
            $this->fail('A non-demo identity must not be reused');
        } catch (LogicException) {
            $this->assertSame($before, $admin->refresh()->getAttributes());
        }
    }
}
