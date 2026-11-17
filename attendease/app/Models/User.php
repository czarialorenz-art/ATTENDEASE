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

    /** Words that join a compound surname, e.g. "Dela Cruz", "De los Santos", "San Juan". */
    private const SURNAME_PARTICLES = ['de', 'del', 'dela', 'la', 'las', 'los', 'delos', 'di', 'da', 'san', 'santa', 'sta.', 'sto.', 'van', 'von', 'y'];

    /** Name suffixes kept at the end, e.g. "Jr.", "III". */
    private const NAME_SUFFIXES = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'];

    /**
     * Splits the single full-name field into surname, given names (with middle initial) and suffix.
     * "Maria Teresa A. Loreno" → [Loreno, Maria Teresa A., null];
     * "Juan Miguel Dela Cruz Jr." → [Dela Cruz, Juan Miguel, Jr.].
     *
     * @return array{surname: string, given: string, suffix: ?string}
     */
    public function nameParts(): array
    {
        $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $suffix = null;

        if (count($words) > 2 && in_array(strtolower(end($words)), self::NAME_SUFFIXES, true)) {
            $suffix = array_pop($words);
        }
        if (count($words) < 2) {
            return ['surname' => (string) ($words[0] ?? ''), 'given' => '', 'suffix' => $suffix];
        }

        // The surname is the last word plus any particles right before it ("Dela", "De los"),
        // as long as at least one given name is left.
        $start = count($words) - 1;
        while ($start > 1 && in_array(strtolower($words[$start - 1]), self::SURNAME_PARTICLES, true)) {
            $start--;
        }

        return [
            'surname' => implode(' ', array_slice($words, $start)),
            'given' => implode(' ', array_slice($words, 0, $start)),
            'suffix' => $suffix,
        ];
    }

    /** "Loreno, Maria Teresa A." / "Dela Cruz, Juan Miguel Jr." for class lists and reports. */
    public function listName(): string
    {
        ['surname' => $surname, 'given' => $given, 'suffix' => $suffix] = $this->nameParts();

        return trim($surname.($given !== '' ? ', '.$given : '').($suffix ? ' '.$suffix : ''));
    }

    /** Sort key: surname, then given names (case-insensitive). */
    public function surnameSortKey(): string
    {
        ['surname' => $surname, 'given' => $given] = $this->nameParts();

        return mb_strtolower($surname.' '.$given);
    }

    /** FR-PDF-03: the surname, used in file names. */
    public function lastName(): string
    {
        return $this->nameParts()['surname'];
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
