<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FR-AUTH-09: a browser the user has signed in from, identified by a hashed cookie token. */
class UserDevice extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'token_hash', 'user_agent', 'ip', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
