<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contents = $request->user()->contents()->latest()->latest('id')->get();

        return response()->json($contents);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'topic' => ['required', 'string', 'max:255'],
            'tone' => ['nullable', 'string', 'max:255'],
            'length' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $content = $request->user()->contents()->create($validated);

        return response()->json($content->refresh(), 201);
    }
}
