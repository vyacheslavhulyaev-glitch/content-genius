<?php

namespace App\Models;

use App\Enums\ContentLanguage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentGroup extends Model
{
    protected $fillable = ['primary_language'];

    protected $casts = ['primary_language' => ContentLanguage::class];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(Content::class)->orderBy('id');
    }
}
