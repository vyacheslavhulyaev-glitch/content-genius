<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderCall extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer', 'output_tokens' => 'integer', 'total_tokens' => 'integer',
            'reserved_tokens' => 'integer', 'estimated_cost' => 'decimal:8', 'reserved_cost' => 'decimal:8',
            'input_price_per_million' => 'decimal:6', 'output_price_per_million' => 'decimal:6',
            'started_at' => 'datetime', 'finished_at' => 'datetime',
        ];
    }

    public function aiRequest(): BelongsTo
    {
        return $this->belongsTo(AIRequest::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }
}
