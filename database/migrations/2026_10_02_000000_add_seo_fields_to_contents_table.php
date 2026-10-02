<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->string('primary_keyword', 120)->nullable();
            $table->json('secondary_keywords')->nullable();
            $table->string('meta_title', 60)->nullable();
            $table->string('meta_description', 160)->nullable();
            $table->json('links')->nullable();
            $table->string('generated_title')->nullable();
            $table->string('generated_meta_title', 60)->nullable();
            $table->string('generated_meta_description', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn([
                'primary_keyword', 'secondary_keywords', 'meta_title', 'meta_description', 'links',
                'generated_title', 'generated_meta_title', 'generated_meta_description',
            ]);
        });
    }
};
