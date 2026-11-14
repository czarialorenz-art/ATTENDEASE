<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Archiving (FR-ATT-16) uses Laravel soft deletes on the archived_at column, so an archived
 * session drops out of every normal query: lists, reports, history and QR scans. It is
 * permanently deleted (with its records, scans and flags) one year after it was archived.
 */
class AttendanceSession extends Model
{
    use HasFactory, MassPrunable, SoftDeletes;

    public const DELETED_AT = 'archived_at';

    /** How long an archived session is kept before it is deleted for good. */
    public const KEEP_ARCHIVED_DAYS = 365;

    public const TIME_IN = 'time_in';
    public const TIME_OUT = 'time_out';
    public const STAGES = [self::TIME_IN, self::TIME_OUT];

    protected $fillable = [
        'class_id', 'date', 'title', 'grace_minutes',
        'time_in_opened_at', 'time_in_closed_at', 'time_out_opened_at', 'time_out_closed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'grace_minutes' => 'integer',
            'time_in_opened_at' => 'datetime',
            'time_in_closed_at' => 'datetime',
            'time_out_opened_at' => 'datetime',
            'time_out_closed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** Archived sessions older than a year: deleted by `php artisan model:prune` (scheduled daily). */
    public function prunable(): Builder
    {
        return static::onlyTrashed()->where('archived_at', '<=', now()->subDays(self::KEEP_ARCHIVED_DAYS));
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** When an archived session will be deleted for good. */
    public function deletesOn(): ?\Carbon\CarbonInterface
    {
        return $this->archived_at?->copy()->addDays(self::KEEP_ARCHIVED_DAYS);
    }

    /** The date can only change before any scanning has started. */
    public function hasStarted(): bool
    {
        return $this->time_in_opened_at !== null;
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function isStageOpen(string $stage): bool
    {
        return $this->{"{$stage}_opened_at"} !== null && $this->{"{$stage}_closed_at"} === null;
    }

    public function openStage(): ?string
    {
        foreach (self::STAGES as $stage) {
            if ($this->isStageOpen($stage)) {
                return $stage;
            }
        }

        return null;
    }

    /** FR-ATT-03: compared as a local date in the app timezone (Asia/Manila). */
    public function isToday(): bool
    {
        return $this->date->toDateString() === now()->toDateString();
    }

    /** A session counts as held once its Time In stage has been opened. */
    public function isHeld(): bool
    {
        return $this->time_in_opened_at !== null;
    }

    /** FR-ATT-09: Present if within time_in_opened_at + grace, otherwise Late. */
    public function statusForTimeIn(CarbonInterface $scannedAt): string
    {
        $deadline = $this->time_in_opened_at->copy()->addMinutes($this->grace_minutes);

        return $scannedAt->lessThanOrEqualTo($deadline) ? AttendanceRecord::PRESENT : AttendanceRecord::LATE;
    }

    public static function stageLabel(string $stage): string
    {
        return $stage === self::TIME_IN ? 'Time In' : 'Time Out';
    }
}
