<?php

namespace App\Services;

use App\Exceptions\GenerationRateLimited;
use App\Models\User;

class GenerationQuota
{
    // The caller holds this user's row lock until the AIRequest reservation is committed.
    public function assertAvailable(User $user): void
    {
        $window = max(1, (int) config('generation.quota.window_seconds'));
        $limit = max(1, (int) config('generation.quota.requests'));
        $query = $user->aiRequests()->where('created_at', '>', now()->subSeconds($window));
        $count = (clone $query)->count();
        if ($count >= $limit) {
            $expires = (clone $query)->orderBy('created_at')->orderBy('id')->skip($count - $limit)->firstOrFail()->created_at;
            throw new GenerationRateLimited($expires->timestamp + $window);
        }
    }
}
