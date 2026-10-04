<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_budget_locks', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('version')->default(0);
        });
        DB::table('ai_budget_locks')->insert(['id' => 1, 'version' => 0]);

        Schema::create('provider_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->nullable()->constrained('ai_requests')->nullOnDelete();
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('model');
            $table->string('response_model')->nullable();
            $table->string('operation', 32);
            $table->string('status', 16);
            $table->unsignedBigInteger('input_tokens')->nullable();
            $table->unsignedBigInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('total_tokens')->nullable();
            $table->decimal('input_price_per_million', 16, 6)->nullable();
            $table->decimal('output_price_per_million', 16, 6)->nullable();
            $table->decimal('estimated_cost', 16, 8)->nullable();
            $table->string('currency', 3);
            $table->unsignedBigInteger('reserved_tokens');
            $table->decimal('reserved_cost', 16, 8)->nullable();
            $table->date('budget_date')->index();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['operation', 'budget_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_calls');
        Schema::dropIfExists('ai_budget_locks');
    }
};
