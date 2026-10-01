<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->string('content_language', 2)->default('en');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn('content_language');
        });
    }
};
