<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'title',
        'user_id',
    ];

    /**
     * Get all messages for this session.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class)->orderBy('created_at', 'asc');
    }

    /**
     * Get the user that owns this session (nullable for guest).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
