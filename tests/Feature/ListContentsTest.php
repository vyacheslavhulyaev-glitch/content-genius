<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListContentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_list_contents(): void
    {
        $this->getJson('/api/contents')->assertUnauthorized();
    }

    public function test_only_the_authenticated_users_contents_are_returned(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $content = $user->contents()->create([
            'title' => 'My draft', 'topic' => 'Laravel', 'tone' => 'Friendly', 'length' => 'Short',
        ])->refresh();
        $otherUser->contents()->create(['title' => 'Private draft', 'topic' => 'Private topic']);

        $this->actingAs($user, 'web')->getJson('/api/contents')
            ->assertOk()
            ->assertExactJson([$content->toArray()]);

        $this->getJson('/api/contents?user_id='.$otherUser->id)
            ->assertOk()
            ->assertExactJson([$content->toArray()]);
    }

    public function test_contents_are_ordered_by_creation_time_then_id_newest_first(): void
    {
        $user = User::factory()->create();
        $newer = $user->contents()->create([
            'title' => 'Newer', 'topic' => 'Laravel',
        ]);
        $newer->forceFill(['created_at' => '2026-09-20 12:00:00'])->save();
        $older = $user->contents()->create([
            'title' => 'Older', 'topic' => 'Laravel',
        ]);
        $older->forceFill(['created_at' => '2026-09-19 12:00:00'])->save();
        $sameTime = $user->contents()->create([
            'title' => 'Same time', 'topic' => 'Laravel',
        ]);
        $sameTime->forceFill(['created_at' => '2026-09-20 12:00:00'])->save();

        $response = $this->actingAs($user, 'web')->getJson('/api/contents')->assertOk();

        $this->assertSame([$sameTime->id, $newer->id, $older->id], array_column($response->json(), 'id'));
    }

    public function test_a_user_without_contents_receives_an_empty_array(): void
    {
        User::factory()->create()->contents()->create(['title' => 'Private draft', 'topic' => 'Laravel']);

        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/contents')
            ->assertOk()
            ->assertExactJson([]);
    }
}
