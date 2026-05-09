<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Content extends Model
{
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
