<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManageContentTest extends TestCase
{
    use RefreshDatabase;

    private function content(User $user): Content
    {
        $content = $user->contents()->create([
            'title' => 'Title', 'topic' => 'Topic', 'tone' => 'Professional', 'length' => 'Short',
            'generated_content' => 'Original text', 'metadata' => ['private' => 'value'],
        ]);
        $content->forceFill(['generation_fingerprint' => $content->generationInputs()->fingerprint()])->save();

        return $content->refresh();
    }

    #[DataProvider('methods')]
    public function test_guest_and_foreign_or_missing_content_are_rejected(string $method): void
    {
        $content = $this->content(User::factory()->create());
        $this->json($method, "/api/contents/{$content->id}")->assertUnauthorized();
        $this->actingAs(User::factory()->admin()->create(), 'web');
        foreach ([$content->id, $content->id + 100] as $id) {
            $this->json($method, "/api/contents/{$id}")->assertNotFound();
        }
        $this->assertDatabaseHas('contents', ['id' => $content->id]);
    }

    public static function methods(): array
    {
        return [['PATCH'], ['DELETE']];
    }

    #[DataProvider('inputFields')]
    public function test_partial_edits_are_persistently_stale_and_reverting_clears_staleness(string $field): void
    {
        $user = User::factory()->create();
        $content = $this->content($user);
        $original = $content->toArray();
        $fingerprint = $content->generation_fingerprint;
        $this->actingAs($user, 'web')->patchJson("/api/contents/{$content->id}", [$field => '  Changed value  '])
            ->assertOk()->assertJsonPath($field, 'Changed value')
            ->assertJsonPath('generated_content', 'Original text')
            ->assertJsonPath('is_generation_stale', true)->assertJsonMissingPath('generation_fingerprint');
        $this->getJson('/api/contents')->assertOk()->assertJsonPath('0.is_generation_stale', true)
            ->assertJsonMissingPath('0.generation_fingerprint');
        $content->refresh();
        $this->assertSame($fingerprint, $content->generation_fingerprint);
        foreach (array_diff(['title', 'topic', 'tone', 'length'], [$field]) as $unchanged) {
            $this->assertSame($original[$unchanged], $content->$unchanged);
        }
        $this->patchJson("/api/contents/{$content->id}", [$field => $original[$field]])
            ->assertOk()->assertJsonPath('is_generation_stale', false);
        $this->assertFalse($content->fresh()->is_generation_stale);
    }

    public static function inputFields(): array
    {
        return [['title'], ['topic'], ['tone'], ['length']];
    }

    public function test_forbidden_fields_are_ignored_and_do_not_make_content_stale(): void
    {
        $user = User::factory()->create();
        $content = $this->content($user);
        $before = $content->getAttributes();
        $this->actingAs($user, 'web')->patchJson("/api/contents/{$content->id}", [
            'user_id' => User::factory()->create()->id,
            'generated_content' => 'Injected', 'generation_fingerprint' => 'Injected',
            'metadata' => ['injected' => true], 'created_at' => '2000-01-01',
            'status' => 'completed', 'tokens_used' => 100, 'cost' => 100,
        ])->assertOk()->assertJsonPath('is_generation_stale', false)->assertJsonMissingPath('generation_fingerprint');
        $this->assertSame($before, $content->refresh()->getAttributes());
        $this->assertDatabaseCount('ai_requests', 0);
        $this->assertFalse($content->isFillable('generation_fingerprint'));

        $content->update(['metadata' => ['different' => true]]);
        $this->assertFalse($content->refresh()->is_generation_stale);
    }

    public function test_partial_empty_patch_and_optional_normalization(): void
    {
        $user = User::factory()->create();
        $content = $user->contents()->create(['title' => 'Title', 'topic' => 'Topic'])->refresh();
        $this->actingAs($user, 'web')->patchJson("/api/contents/{$content->id}", [])
            ->assertOk()->assertExactJson($content->toArray());
        $this->patchJson("/api/contents/{$content->id}", ['tone' => ' ', 'length' => null])
            ->assertOk()->assertJsonPath('tone', null)->assertJsonPath('length', null)
            ->assertJsonPath('is_generation_stale', false);
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_edits_leave_content_unchanged(array $fields): void
    {
        $user = User::factory()->create();
        $content = $this->content($user);
        $before = $content->getAttributes();
        $this->actingAs($user, 'web')->patchJson("/api/contents/{$content->id}", $fields)
            ->assertUnprocessable()->assertJsonValidationErrors(array_keys($fields));
        $this->assertSame($before, $content->refresh()->getAttributes());
    }

    public static function invalidInputs(): array
    {
        return [
            [['title' => '', 'topic' => null]],
            [['title' => [], 'topic' => [], 'tone' => [], 'length' => []]],
            [array_fill_keys(['title', 'topic', 'tone', 'length'], str_repeat('a', 256))],
        ];
    }

    public function test_creation_hides_fingerprint_and_drafts_are_not_stale(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/contents', [
            'title' => 'Title', 'topic' => 'Topic', 'generation_fingerprint' => 'Injected',
        ])->assertCreated()->assertJsonPath('is_generation_stale', false)->assertJsonMissingPath('generation_fingerprint');
        $this->assertNull(Content::sole()->generation_fingerprint);
    }

    public function test_owner_delete_preserves_request_history_and_admin_sees_null_content(): void
    {
        $user = User::factory()->admin()->create();
        $content = $this->content($user);
        $request = $user->aiRequests()->create([
            'content_id' => $content->id, 'status' => 'completed', 'tokens_used' => 15,
        ]);
        $this->actingAs($user, 'web')->deleteJson("/api/contents/{$content->id}")->assertNoContent();
        $this->assertDatabaseMissing('contents', ['id' => $content->id]);
        $this->assertDatabaseCount('ai_requests', 1);
        $this->assertNull($request->refresh()->content_id);
        $this->assertSame('completed', $request->status);
        $this->assertSame(15, $request->tokens_used);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('recent_ai_requests.0.content', null);
    }

    public function test_migration_backfills_existing_generated_rows_and_leaves_drafts_null(): void
    {
        $user = User::factory()->create();
        $generated = $this->content($user);
        $empty = $user->contents()->create(['title' => 'Unicode title é', 'topic' => 'Topic', 'tone' => '', 'generated_content' => '']);
        $draft = $user->contents()->create(['title' => 'Draft', 'topic' => 'Topic']);
        $expected = [$generated->id => $generated->generationInputs()->fingerprint(), $empty->id => $empty->generationInputs()->fingerprint()];
        $migration = require database_path('migrations/2026_09_26_000001_add_generation_fingerprint_to_contents_table.php');
        $migration->down();
        $migration->up();

        foreach ($expected as $id => $fingerprint) {
            $this->assertSame($fingerprint, DB::table('contents')->where('id', $id)->value('generation_fingerprint'));
            $this->assertFalse(Content::findOrFail($id)->is_generation_stale);
        }
        $this->assertNull($draft->refresh()->generation_fingerprint);
    }
}
