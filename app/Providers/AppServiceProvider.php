<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use OpenAI;
use OpenAI\Contracts\ClientContract;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ClientContract::class, fn () => OpenAI::client(config('services.openai.key')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('view-admin-dashboard', fn (User $user): bool => ! $user->is_demo && ($user->is_admin === true || $user->is_admin_demo === true));
        Gate::define('mutate-content', fn (User $user): Response => $user->is_admin_demo
            ? Response::deny('The recruiter admin demo is read-only.')
            : Response::allow());
    }
}
