<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** FR-ATT-18: a student's request to change an Absent or Late record. */
class AttendanceCorrection extends Model
{
    use HasFactory;

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** What each status can be corrected to. */
    public const ALLOWED = [
        AttendanceRecord::ABSENT => [AttendanceRecord::PRESENT, AttendanceRecord::LATE],
        AttendanceRecord::LATE => [AttendanceRecord::PRESENT],
    ];

    protected $fillable = [
        'attendance_record_id', 'student_id', 'current_status', 'requested_status',
        'reason', 'status', 'teacher_note', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    /** The session this request is about (also found when the session is archived). */
    public function session(): ?AttendanceSession
    {
        return $this->record?->attendanceSession()->withTrashed()->first();
    }
}
