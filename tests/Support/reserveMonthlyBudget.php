<?php

use App\Exceptions\AiBudgetExceeded;
use App\Models\AIRequest;
use App\Services\GlobalAiBudget;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    throw new RuntimeException('The reservation worker is restricted to tests.');
}

config([
    'database.default' => 'sqlite', 'database.connections.sqlite.url' => null,
    'database.connections.sqlite.database' => $argv[1], 'database.connections.sqlite.busy_timeout' => 5000,
    'ai_usage.pricing.gpt-4o-mini' => ['input' => 0, 'output' => 1],
    'ai_usage.budget.daily_cost' => null, 'ai_usage.budget.monthly_cost' => 1.00,
    'ai_usage.budget.daily_calls' => null, 'ai_usage.budget.daily_tokens' => null,
]);
DB::purge('sqlite');
DB::listen(function ($query): void {
    if (str_contains($query->sql, 'ai_budget_locks')) {
        // Hold the acquired mutex long enough for the other worker to contend for it.
        usleep(100000);
    }
});
$request = AIRequest::findOrFail($argv[3]);
echo "READY\n";
flush();
$deadline = microtime(true) + 10;
while (! is_file($argv[2])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Timed out waiting for the concurrent reservation gate.');
    }
    usleep(10000);
}

try {
    app(GlobalAiBudget::class)->reserve($request, 'generation', ['model' => 'gpt-4o-mini', 'max_completion_tokens' => 10000]);
    echo "reserved\n";
} catch (AiBudgetExceeded $exception) {
    if ($exception->render()->getData()->error !== 'Monthly AI budget reached') {
        throw $exception;
    }
    echo "monthly_rejected\n";
}
