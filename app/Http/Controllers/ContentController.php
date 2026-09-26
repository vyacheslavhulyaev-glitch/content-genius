<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    public function update(Request $request, string $content): JsonResponse
    {
        $content = $request->user()->contents()->findOrFail($content);
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'topic' => ['sometimes', 'required', 'string', 'max:255'],
            'tone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'length' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $content->update($validated);

        return response()->json($content->refresh());
    }

    public function destroy(Request $request, string $content): Response
    {
        $request->user()->contents()->findOrFail($content)->delete();

        return response()->noContent();
    }
}
