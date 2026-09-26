<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->string('generation_fingerprint', 64)->nullable();
        });

        // Keep this canonical representation independent of future model changes.
        DB::table('contents')->whereNotNull('generated_content')
            ->select(['id', 'title', 'topic', 'tone', 'length'])->chunkById(500, function ($contents): void {
                foreach ($contents as $content) {
                    $inputs = array_map(fn ($value): string => trim($value ?? ''), [
                        $content->title, $content->topic, $content->tone, $content->length,
                    ]);
                    DB::table('contents')->where('id', $content->id)->whereNull('generation_fingerprint')
                        ->update(['generation_fingerprint' => hash('sha256', json_encode($inputs, JSON_THROW_ON_ERROR))]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn('generation_fingerprint');
        });
    }
};
