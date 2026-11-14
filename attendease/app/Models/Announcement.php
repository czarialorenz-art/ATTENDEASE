<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FR-ANN: a class announcement; a "cancellation" also names the class meeting that won't happen. */
class Announcement extends Model
{
    use HasFactory;

    public const GENERAL = 'general';
    public const CANCELLATION = 'cancellation';
    public const TYPES = [self::GENERAL => 'Announcement', self::CANCELLATION => 'Class cancellation'];

    protected $fillable = ['class_id', 'author_id', 'type', 'title', 'body', 'cancelled_date'];

    protected function casts(): array
    {
        return ['cancelled_date' => 'date'];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function isCancellation(): bool
    {
        return $this->type === self::CANCELLATION;
    }

    /** A cancellation for today or later. */
    public function isUpcomingCancellation(): bool
    {
        return $this->isCancellation() && $this->cancelled_date && $this->cancelled_date->gte(now()->startOfDay());
    }
}
