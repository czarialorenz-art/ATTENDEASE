<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A class (section of a subject). Named SchoolClass because "Class" is reserved in PHP.
 */
class SchoolClass extends Model
{
    use HasFactory;

    public const SEMESTERS = ['1st', '2nd', 'Summer'];

    /** FR-CLS-02: uppercase letters and digits without 0/O and 1/I. */
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $table = 'classes';

    protected $fillable = [
        'teacher_id', 'subject', 'year_level', 'section', 'school_term', 'semester',
        'semester_start', 'semester_end', 'code', 'latitude', 'longitude', 'radius_m',
    ];

    protected function casts(): array
    {
        return [
            'semester_start' => 'date',
            'semester_end' => 'date',
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_m' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SchoolClass $class) {
            $class->code ??= static::generateCode();
        });
    }

    public static function generateCode(int $length = 7): string
    {
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class, 'class_id')->orderBy('day_of_week')->orderBy('start_time');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'class_id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'enrollments', 'class_id', 'student_id')
            ->withPivot('status', 'decided_at')
            ->withTimestamps();
    }

    public function approvedStudents(): BelongsToMany
    {
        return $this->students()->wherePivot('status', Enrollment::APPROVED);
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class, 'class_id');
    }

    /** FR-ANN: newest first. */
    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class, 'class_id')->latest()->latest('id');
    }

    /** FR-ANN-04: the cancellation announced for a date, if any. */
    public function cancellationOn(\Carbon\CarbonInterface|string $date): ?Announcement
    {
        $day = $date instanceof \Carbon\CarbonInterface ? $date->toDateString() : \Illuminate\Support\Carbon::parse($date)->toDateString();

        return Announcement::where('class_id', $this->id)
            ->where('type', Announcement::CANCELLATION)
            ->whereDate('cancelled_date', $day)
            ->latest('id')->first();
    }

    /** FR-ATT-18: correction requests in this class waiting for the teacher. */
    public function pendingCorrectionsCount(): int
    {
        return AttendanceCorrection::where('status', AttendanceCorrection::PENDING)
            ->whereHas('record', fn ($q) => $q->whereHas('attendanceSession', fn ($s) => $s->withTrashed()->where('class_id', $this->id)))
            ->count();
    }

    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function label(): string
    {
        return "{$this->subject} · {$this->section}";
    }
}
