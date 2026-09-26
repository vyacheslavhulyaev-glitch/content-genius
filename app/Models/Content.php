<?php

namespace App\Models;

use App\Support\GenerationInputs;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Content extends Model
{
    protected $hidden = ['generation_fingerprint'];

    protected $appends = ['is_generation_stale'];

    public function generationInputs(): GenerationInputs
    {
        return new GenerationInputs($this->title, $this->topic, $this->tone, $this->length);
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
        'generated_content',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function aiRequests()
    {
        return $this->hasMany(AIRequest::class);
    }
}
