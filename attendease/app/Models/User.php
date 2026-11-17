<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'id_number', 'email', 'password',
        'course', 'year', 'section', 'department',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Classes this user created (and therefore teaches). */
    public function taughtClasses(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'teacher_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }

    /** Classes this user is an approved student in. */
    public function approvedClasses(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'enrollments', 'student_id', 'class_id')
            ->wherePivot('status', Enrollment::APPROVED)
            ->withTimestamps();
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    /**
     * Teaches at least one class and isn't a student (approved or pending) in any:
     * the dashboard and top bar then hide student-only things such as "Classes I'm in" and Scan QR.
     */
    public function isTeacherOnly(): bool
    {
        return $this->taughtClasses()->exists()
            && ! $this->enrollments()->whereIn('status', [Enrollment::APPROVED, Enrollment::PENDING])->exists();
    }

    public function teaches(SchoolClass $class): bool
    {
        return (int) $class->teacher_id === (int) $this->id;
    }

    public function isApprovedIn(SchoolClass $class): bool
    {
        return $this->enrollments()
            ->where('class_id', $class->id)
            ->where('status', Enrollment::APPROVED)
            ->exists();
    }

    /** FR-PDF-03: the last name is the final word of the full name. */
    public function lastName(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return (string) end($parts);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
