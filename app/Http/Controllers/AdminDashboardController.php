<?php

namespace App\Http\Controllers;

use App\Models\AIRequest;
use App\Models\Content;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $requests = AIRequest::query()
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS completed', ['completed'])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS failed', ['failed'])
            ->selectRaw('COUNT(CASE WHEN status = ? THEN 1 END) AS pending', ['pending'])
            ->selectRaw('COALESCE(SUM(tokens_used), 0) AS tokens')
            ->first();

        $recent = AIRequest::query()
            ->select(['id', 'user_id', 'content_id', 'status', 'tokens_used', 'created_at'])
            ->with(['user:id,name,email', 'content:id,title'])
            ->latest()->latest('id')->limit(20)->get();

        return response()->json([
            'summary' => [
                'total_users' => User::count(),
                'total_contents' => Content::count(),
                'generated_contents' => Content::whereNotNull('generated_content')->count(),
                'total_ai_requests' => (int) $requests->total,
                'completed_ai_requests' => (int) $requests->completed,
                'failed_ai_requests' => (int) $requests->failed,
                'pending_ai_requests' => (int) $requests->pending,
                'total_tokens_used' => (int) $requests->tokens,
            ],
            'recent_ai_requests' => $recent->map(fn (AIRequest $request): array => [
                'id' => $request->id,
                'user' => [
                    'id' => $request->user->id,
                    'name' => $request->user->name,
                    'email' => $request->user->email,
                ],
                'content' => $request->content === null ? null : [
                    'id' => $request->content->id,
                    'title' => $request->content->title,
                ],
                'status' => $request->status,
                'tokens_used' => $request->tokens_used === null ? null : (int) $request->tokens_used,
                'created_at' => $request->created_at,
            ]),
        ]);
    }
}
