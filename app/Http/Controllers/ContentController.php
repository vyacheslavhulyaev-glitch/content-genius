<?php

namespace App\Http\Controllers;

use App\Actions\ManageContent;
use App\Enums\ContentLanguage;
use App\Http\Resources\ContentResource;
use App\Models\Content;
use App\Services\GenerationInputPolicy;
use App\Support\GenerationInputs;
use App\Support\SeoFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ContentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $contents = $request->user()->contents()->with(Content::GROUP_RELATIONS)->latest()->latest('id')->get();

        return response()->json(ContentResource::collection($contents));
    }

    public function store(Request $request, ManageContent $manage, GenerationInputPolicy $inputPolicy): JsonResponse
    {
        $fields = SeoFields::normalize($request->all());
        $primary = $fields['primary_keyword'] ?? $fields['title'] ?? null;
        $validated = Validator::make($fields, [
            ...GenerationInputPolicy::rules(),
            'content_language' => ['sometimes', 'required', Rule::enum(ContentLanguage::class)],
            'metadata' => ['nullable', 'array'],
            ...SeoFields::rules(is_string($primary) ? $primary : null),
        ])->validate();
        $inputPolicy->validate(new GenerationInputs($validated['title'], $validated['topic'], $validated['tone'] ?? null, $validated['length'] ?? null,
            ContentLanguage::from($validated['content_language'] ?? 'en'), $validated));

        $content = $manage->create($request->user(), $validated);

        return response()->json(new ContentResource($content), 201);
    }

    public function update(Request $request, string $content, ManageContent $manage, GenerationInputPolicy $inputPolicy): JsonResponse
    {
        $stored = $request->user()->contents()->findOrFail($content);
        $fields = SeoFields::normalize($request->all());
        $combined = array_replace($stored->only(['title', 'topic', 'tone', 'length', ...SeoFields::INPUTS]), $fields);
        $primary = $combined['primary_keyword'] ?: ($combined['title'] ?? null);
        $validated = Validator::make($fields, [
            ...array_map(fn ($rules) => ['sometimes', ...$rules], GenerationInputPolicy::rules()),
            'content_language' => ['sometimes', 'required', Rule::enum(ContentLanguage::class)],
            ...SeoFields::rules(is_string($primary) ? $primary : null),
        ])->validate();
        $inputPolicy->validate(new GenerationInputs($combined['title'], $combined['topic'], $combined['tone'], $combined['length'],
            isset($validated['content_language']) ? ContentLanguage::from($validated['content_language']) : $stored->content_language, $combined));
        $content = $manage->update($request->user(), $content, $validated);

        return response()->json(new ContentResource($content));
    }

    public function destroy(Request $request, string $content, ManageContent $manage): Response
    {
        $manage->delete($request->user(), $content);

        return response()->noContent();
    }
}
