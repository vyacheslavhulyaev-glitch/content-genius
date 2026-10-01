<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Run with application writes paused. Each row is restartable and atomic.
        DB::table('contents')->whereNull('content_group_id')->orderBy('id')->chunkById(500, function ($contents): void {
            foreach ($contents as $content) {
                DB::transaction(function () use ($content): void {
                    $current = DB::table('contents')->where('id', $content->id)->lockForUpdate()->first();
                    if ($current === null || $current->content_group_id !== null) {
                        return;
                    }
                    $groupId = DB::table('content_groups')->insertGetId([
                        'user_id' => $current->user_id,
                        'primary_language' => $current->content_language,
                        'created_at' => $current->created_at,
                        'updated_at' => $current->updated_at,
                    ]);
                    DB::table('contents')->where('id', $current->id)->update(['content_group_id' => $groupId]);
                });
            }
        });

        Schema::table('contents', function (Blueprint $table): void {
            $table->unsignedBigInteger('content_group_id')->nullable(false)->change();
            $table->unique(['content_group_id', 'content_language']);
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropUnique(['content_group_id', 'content_language']);
            $table->unsignedBigInteger('content_group_id')->nullable()->change();
        });
    }
};
