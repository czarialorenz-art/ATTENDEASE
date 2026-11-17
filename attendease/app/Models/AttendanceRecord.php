<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AttendanceRecord extends Model
{
    use HasFactory;

    public const PRESENT = 'present';
    public const LATE = 'late';
    public const ABSENT = 'absent';
    public const STATUSES = [self::PRESENT, self::LATE, self::ABSENT];

    public const SOURCE_QR = 'qr';
    public const SOURCE_MANUAL = 'manual';

    protected $fillable = ['attendance_session_id', 'student_id', 'status', 'time_in_at', 'time_out_at', 'source'];

    protected function casts(): array
    {
        return [
            'time_in_at' => 'datetime',
            'time_out_at' => 'datetime',
        ];
    }

    public function attendanceSession(): BelongsTo
    {
        return $this->belongsTo(AttendanceSession::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function scanLogs(): HasMany
    {
        return $this->hasMany(ScanLog::class);
    }

    /** FR-ATT-18: correction requests for this record, newest first. */
    public function corrections(): HasMany
    {
        return $this->hasMany(AttendanceCorrection::class)->latest('id');
    }

    public function latestCorrection(): HasOne
    {
        return $this->hasOne(AttendanceCorrection::class)->latestOfMany('id');
    }

    /** Can the student ask to change this record right now? */
    public function canRequestCorrection(): bool
    {
        return isset(AttendanceCorrection::ALLOWED[$this->status])
            && ! ($this->relationLoaded('latestCorrection') ? $this->latestCorrection?->isPending() : $this->corrections()->where('status', AttendanceCorrection::PENDING)->exists());
    }
}
