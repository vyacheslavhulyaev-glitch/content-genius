<?php

namespace App\Http\Controllers;

use App\Actions\ManageContent;
use App\Enums\ContentLanguage;
use App\Http\Resources\ContentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentTranslationController extends Controller
{
    public function __invoke(Request $request, string $content, ManageContent $manage): JsonResponse
    {
        $request->user()->contents()->findOrFail($content);
        $validated = $request->validate([
            'content_language' => ['required', Rule::enum(ContentLanguage::class)],
        ]);
        $translation = $manage->translate($request->user(), $content, ContentLanguage::from($validated['content_language']));

        return response()->json(new ContentResource($translation), 201);
    }
}
