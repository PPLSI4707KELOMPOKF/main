<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegulationChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'regulation_document_id',
        'chunk_uid',
        'chunk_index',
        'content',
        'metadata',
        'embedding_status',
        'embedded_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'embedded_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(RegulationDocument::class, 'regulation_document_id');
    }
}
