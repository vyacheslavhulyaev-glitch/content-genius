<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AIRequest extends Model
{
    protected $table = 'ai_requests';

    protected $fillable = [
        'user_id',
        'content_id',
        'status',
        'tokens_used',
        'cost',
        'error_message',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function content()
    {
        return $this->belongsTo(Content::class);
    }
}
