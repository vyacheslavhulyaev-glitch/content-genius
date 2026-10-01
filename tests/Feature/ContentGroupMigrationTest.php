<?php

namespace Tests\Feature;

use App\Models\Content;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContentGroupMigrationTest extends TestCase
{
    // Exercise schema rebuilds as migrations run, without an outer test transaction.
    use DatabaseMigrations;

    public function test_backfill_and_rollback_preserve_content_ids_text_and_request_links(): void
    {
        $contents = collect(['en', 'uk', 'de'])->map(function ($language) {
            $content = Content::factory()->create([
                'content_language' => $language, 'generated_content' => 'Stored output '.$language,
                'metadata' => ['preserve' => true],
            ])->refresh();
            $content->forceFill(['generation_fingerprint' => $content->generationInputs()->fingerprint()])->save();
            $content->user->aiRequests()->create(['content_id' => $content->id, 'status' => 'completed']);

            return $content->getAttributes();
        });
        $contents->push(Content::factory()->create()->refresh()->getAttributes());
        $requests = DB::table('ai_requests')->orderBy('id')->get()->toArray();
        $schema = require database_path('migrations/2026_09_30_000001_create_content_groups_table.php');
        $backfill = require database_path('migrations/2026_09_30_000002_backfill_content_groups.php');
        $backfill->down();
        $schema->down();
        $schema->up();

        // Simulate resuming after one already committed backfill row.
        $first = $contents->first();
        $firstGroup = DB::table('content_groups')->insertGetId([
            'user_id' => $first['user_id'], 'primary_language' => $first['content_language'],
            'created_at' => $first['created_at'], 'updated_at' => $first['updated_at'],
        ]);
        DB::table('contents')->where('id', $first['id'])->update(['content_group_id' => $firstGroup]);
        $backfill->up();

        $this->assertDatabaseCount('content_groups', 4);
        $this->assertSame($firstGroup, Content::findOrFail($first['id'])->content_group_id);
        $this->assertCount(4, DB::table('contents')->pluck('content_group_id')->unique());
        foreach ($contents as $original) {
            $content = Content::findOrFail($original['id']);
            $this->assertSame($original['user_id'], $content->contentGroup->user_id);
            $this->assertSame($original['content_language'], $content->contentGroup->primary_language->value);
            $this->assertSame($original['created_at'], $content->contentGroup->getRawOriginal('created_at'));
            foreach (array_diff(array_keys($original), ['content_group_id']) as $field) {
                $this->assertSame($original[$field], $content->getRawOriginal($field));
            }
            $this->assertFalse($content->is_generation_stale);
        }
        $this->assertEquals($requests, DB::table('ai_requests')->orderBy('id')->get()->toArray());

        $backfill->down();
        $schema->down();
        $this->assertDatabaseCount('contents', 4);
        $this->assertEquals($requests, DB::table('ai_requests')->orderBy('id')->get()->toArray());
        foreach ($contents as $original) {
            unset($original['content_group_id']);
            $this->assertEquals($original, (array) DB::table('contents')->find($original['id']));
        }
        $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $schema->up();
        $backfill->up();
    }
}
