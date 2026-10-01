<?php

namespace App\Models;

use App\Enums\ContentLanguage;
use App\Support\GenerationInputs;
use Database\Factories\ContentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Content extends Model
{
    /** @use HasFactory<ContentFactory> */
    use HasFactory;

    public const GROUP_RELATIONS = ['contentGroup.contents:id,content_group_id,content_language,title,topic,tone,length,generated_content,generation_fingerprint'];

    protected $hidden = ['generation_fingerprint'];

    protected $appends = ['is_generation_stale'];

    protected $attributes = ['content_language' => ContentLanguage::English->value];

    public function generationInputs(): GenerationInputs
    {
        return new GenerationInputs($this->title, $this->topic, $this->tone, $this->length, $this->content_language);
    }

    protected function isGenerationStale(): Attribute
    {
        return Attribute::get(fn (): bool => $this->generated_content !== null
            && $this->generationInputs()->fingerprint() !== $this->generation_fingerprint);
    }

    protected $fillable = [
        'user_id',
        'title',
        'topic',
        'tone',
        'length',
        'content_language',
        'generated_content',
        'metadata',
    ];

    protected $casts = [
        'content_language' => ContentLanguage::class,
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contentGroup(): BelongsTo
    {
        return $this->belongsTo(ContentGroup::class);
    }

    public function aiRequests()
    {
        return $this->hasMany(AIRequest::class);
    }
}
