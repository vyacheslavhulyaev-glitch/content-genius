<?php

namespace App\Http\Controllers;

use App\Actions\ManageContent;
use App\Enums\ContentLanguage;
use App\Http\Resources\ContentResource;
use App\Models\Content;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ContentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contents = $request->user()->contents()->with(Content::GROUP_RELATIONS)->latest()->latest('id')->get();

        return response()->json(ContentResource::collection($contents));
    }

    public function store(Request $request, ManageContent $manage): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'topic' => ['required', 'string', 'max:255'],
            'tone' => ['nullable', 'string', 'max:255'],
            'length' => ['nullable', 'string', 'max:255'],
            'content_language' => ['sometimes', 'required', Rule::enum(ContentLanguage::class)],
            'metadata' => ['nullable', 'array'],
        ]);

        $content = $manage->create($request->user(), $validated);

        return response()->json(new ContentResource($content), 201);
    }

    public function update(Request $request, string $content, ManageContent $manage): JsonResponse
    {
        $request->user()->contents()->findOrFail($content);
        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'topic' => ['sometimes', 'required', 'string', 'max:255'],
            'tone' => ['sometimes', 'nullable', 'string', 'max:255'],
            'length' => ['sometimes', 'nullable', 'string', 'max:255'],
            'content_language' => ['sometimes', 'required', Rule::enum(ContentLanguage::class)],
        ]);
        $content = $manage->update($request->user(), $content, $validated);

        return response()->json(new ContentResource($content));
    }

    public function destroy(Request $request, string $content, ManageContent $manage): Response
    {
        $manage->delete($request->user(), $content);

        return response()->noContent();
    }
}
