<?php

namespace App\Actions;

use App\Enums\ContentLanguage;
use App\Models\Content;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageContent
{
    public function create(User $user, array $fields): Content
    {
        return DB::transaction(function () use ($user, $fields): Content {
            $group = $user->contentGroups()->create([
                'primary_language' => $fields['content_language'] ?? ContentLanguage::English,
            ]);
            $content = $user->contents()->make($fields);
            $content->contentGroup()->associate($group);
            $content->save();

            return $content->refresh()->load(Content::GROUP_RELATIONS);
        });
    }

    public function translate(User $user, string $contentId, ContentLanguage $language): Content
    {
        try {
            return DB::transaction(function () use ($user, $contentId, $language): Content {
                $source = $this->lockContent($user, $contentId);
                $this->ensureLanguageAvailable($source, $language);
                $translation = $user->contents()->make([
                    ...$source->only(['title', 'topic', 'tone', 'length']),
                    'content_language' => $language,
                ]);
                $translation->contentGroup()->associate($source->contentGroup);
                $translation->save();

                return $translation->refresh()->load(Content::GROUP_RELATIONS);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateLanguage();
        }
    }

    public function update(User $user, string $contentId, array $fields): Content
    {
        try {
            return DB::transaction(function () use ($user, $contentId, $fields): Content {
                $content = $this->lockContent($user, $contentId);
                $group = $content->contentGroup;
                $language = isset($fields['content_language'])
                    ? ContentLanguage::from($fields['content_language']) : $content->content_language;
                $this->ensureLanguageAvailable($content, $language, $content->id);
                $wasPrimary = $group->primary_language === $content->content_language;
                $content->update($fields);
                if ($wasPrimary && $group->primary_language !== $language) {
                    $group->update(['primary_language' => $language]);
                }

                return $content->refresh()->load(Content::GROUP_RELATIONS);
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateLanguage();
        }
    }

    public function delete(User $user, string $contentId): void
    {
        DB::transaction(function () use ($user, $contentId): void {
            $content = $this->lockContent($user, $contentId);
            $group = $content->contentGroup;
            $content->delete();
            $remaining = $group->contents()->first();
            if ($remaining === null) {
                $group->delete();
            } elseif ($group->primary_language === $content->content_language) {
                // The oldest surviving Content ID becomes the primary version.
                $group->update(['primary_language' => $remaining->content_language]);
            }
        });
    }

    private function lockContent(User $user, string $contentId): Content
    {
        $content = $user->contents()->findOrFail($contentId);
        // All group mutations lock the group before any individual version.
        $group = $user->contentGroups()->lockForUpdate()->findOrFail($content->content_group_id);
        $content = $group->contents()->where('user_id', $user->id)->lockForUpdate()->findOrFail($contentId);

        return $content->setRelation('contentGroup', $group);
    }

    private function ensureLanguageAvailable(Content $content, ContentLanguage $language, ?int $exceptId = null): void
    {
        if ($content->contentGroup->contents()->where('content_language', $language)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))->exists()) {
            throw $this->duplicateLanguage();
        }
    }

    private function duplicateLanguage(): ValidationException
    {
        return ValidationException::withMessages(['content_language' => 'This language version already exists.']);
    }
}
