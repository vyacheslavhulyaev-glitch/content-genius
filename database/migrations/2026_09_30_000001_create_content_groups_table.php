<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('primary_language', ['en', 'uk', 'de']);
            $table->timestamps();
        });

        Schema::table('contents', function (Blueprint $table): void {
            $table->foreignId('content_group_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('content_group_id');
        });
        Schema::dropIfExists('content_groups');
    }
};
