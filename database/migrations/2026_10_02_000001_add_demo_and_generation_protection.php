<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('is_demo')->default(false));
        Schema::table('contents', fn (Blueprint $table) => $table->text('topic')->change());
        Schema::table('ai_requests', fn (Blueprint $table) => $table->index(['user_id', 'created_at'], 'ai_requests_user_created_index'));
    }

    public function down(): void
    {
        Schema::table('ai_requests', fn (Blueprint $table) => $table->dropIndex('ai_requests_user_created_index'));
        // Keep topic as text on rollback to avoid truncating saved briefs.
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_demo'));
    }
};
